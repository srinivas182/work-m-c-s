<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Wurk\Billing\Mail\PrepaidNoticeMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Pay as you go (Sprint 22.5): a package marked prepaid is paid day by day from the business's WURK credits — the
 * monthly price spread over the month's days, at the best credit pack's price per credit — instead of an invoice.
 * Owners are warned at 7, 3 and 1 day(s) left. With nothing left the business keeps working for the grace period
 * (Admin setting), then the usual dunning applies. Nothing is deleted, and a top-up restores the business at once.
 */
final readonly class PrepaidPlans
{
    public const WARN_AT = [7, 3, 1];

    /**
     * WURK's WhatsApp templates (utility, English), submitted by HQ on WURK's own number:
     * low: "{{1}} has about {{2}} days of WURK left. Top up any amount from R20 to keep going: {{3}}"
     * out: "{{1}}'s WURK credits have run out. Everything keeps working for {{2}} more days. Top up from R20: {{3}}"
     */
    public const TEMPLATE_LOW = 'wurk_credits_low';

    public const TEMPLATE_OUT = 'wurk_credits_out';

    public function __construct(private Catalog $catalog, private TenantDirectory $tenants, private TenantContext $context, private BillingSettings $settings,
        private Dunning $dunning, private Messenger $messenger) {}

    /** WURK's price of a credit, in cents excluding VAT (the best active pack), or null when no pack is on sale. */
    public function centsPerCredit(): ?float
    {
        $best = CreditPack::query()->where('active', true)->where('credits', '>', 0)->get()->map(fn (CreditPack $p) => $p->price_cents / $p->credits)->min();

        return $best === null || $best <= 0 ? null : (float) $best;
    }

    /** Credits (thousandths) one day of a package costs, in the month that day falls in. */
    public function dailyMilli(int $monthlyCents, CarbonImmutable $day): ?int
    {
        $perCredit = $this->centsPerCredit();

        return $perCredit === null ? null : (int) ceil($monthlyCents / $day->daysInMonth / $perCredit * 1000);
    }

    /**
     * Where a prepaid business stands: the daily cost, about how many days its credits last, and whether it has run out.
     *
     * @return array{daily_milli: int, days_left: int, ran_out_on: string|null, grace_days: int}|null
     */
    public function status(string $tenantId): ?array
    {
        $sub = $this->catalog->subscriptionOf($tenantId);
        $tenant = $this->tenants->find($tenantId);
        $daily = $sub !== null && $sub['prepaid'] ? $this->dailyMilli((int) $sub['monthly_cents'], CarbonImmutable::now()) : null;
        if ($daily === null || $tenant === null) {
            return null;
        }
        $balance = $this->context->run($tenant, fn () => app(CreditWallet::class)->balance()['balance_milli']);

        return ['daily_milli' => $daily, 'days_left' => intdiv(max(0, $balance), max(1, $daily)), 'ran_out_on' => BillingAccount::query()->find($tenantId)?->prepaid_ran_out_on,
            'grace_days' => (int) $this->settings->dunning()['prepaid_grace_days']];
    }

    /**
     * Take today's price from the business's credits (once a day; safe to run again).
     *
     * @return 'charged'|'already'|'ran_out'|'skipped'
     */
    public function run(string $tenantId): string
    {
        $sub = $this->catalog->subscriptionOf($tenantId);
        $account = BillingAccount::query()->find($tenantId);
        $tenant = $this->tenants->find($tenantId);
        $today = CarbonImmutable::now()->startOfDay();
        $daily = $sub !== null && $sub['prepaid'] ? $this->dailyMilli((int) $sub['monthly_cents'], $today) : null;
        if ($sub === null || $account === null || $tenant === null || $daily === null) {
            return 'skipped';
        }
        if ($account->prepaid_charged_on === $today->toDateString()) {
            return 'already';
        }
        try {
            $this->context->run($tenant, fn () => app(CreditWallet::class)->chargeAmount($daily, 'plan', 'plan:'.$today->toDateString(),
                $sub['package'].' plan, '.$today->format('j M Y')));
        } catch (InsufficientCredits) {
            $this->ranOut($tenantId, $account, $tenant->displayName(), $tenant->handle(), $sub['status'], $today);

            return 'ran_out';
        }
        $this->catalog->markPaid($tenantId, $today, $today->addDay()); // active again, whatever it was
        $account->update(['prepaid_charged_on' => $today->toDateString(), 'prepaid_ran_out_on' => null]);
        $left = (int) ($this->status($tenantId)['days_left'] ?? 0);
        if (in_array($left, self::WARN_AT, true) && $account->prepaid_warned_days !== $left) {
            $this->notify($account, 'low', $tenant->displayName(), $tenant->handle(), $left);
            $account->update(['prepaid_warned_days' => $left]);
        } elseif ($left > max(self::WARN_AT)) {
            $account->update(['prepaid_warned_days' => null]); // topped up: warn again next time
        }

        return 'charged';
    }

    private function ranOut(string $tenantId, BillingAccount $account, string $business, string $handle, string $status, CarbonImmutable $today): void
    {
        $grace = (int) $this->settings->dunning()['prepaid_grace_days'];
        if ($account->prepaid_ran_out_on === null) {
            $account->update(['prepaid_ran_out_on' => $today->toDateString()]);
            $this->notify($account, 'empty', $business, $handle, $grace);

            return;
        }
        if ($status === 'active' && CarbonImmutable::parse($account->prepaid_ran_out_on)->diffInDays($today) >= $grace) {
            $this->catalog->markPastDue($tenantId); // the usual dunning from here: restricted, then suspended
            $this->dunning->notify($tenantId, 'failed');
        }
    }

    /** @param  'low'|'empty'  $kind */
    private function notify(BillingAccount $account, string $kind, string $business, string $handle, int $days): void
    {
        $url = 'https://'.config('wurk.domains.app').'/'.$handle.'/settings/billing';
        Mail::to($account->billing_email)->send(new PrepaidNoticeMail($kind, $business, $days, $url));
        if ($account->billing_phone !== null) {
            // WhatsApp first, on WURK's number (approved templates); SMS when WhatsApp can't deliver it.
            $sent = $this->messenger->whatsappTemplate($account->billing_phone, $kind === 'empty' ? self::TEMPLATE_OUT : self::TEMPLATE_LOW,
                [$business, (string) $days, $url], 'prepaid_'.$kind);
            if ($sent->ok) {
                return;
            }
            $this->messenger->sms($account->billing_phone, $kind === 'empty'
                ? "WURK: {$business}'s credits have run out. Top up from R20 to keep going: {$url}"
                : "WURK: {$business} has about {$days} ".($days === 1 ? 'day' : 'days')." of WURK left. Top up from R20: {$url}", 'prepaid_'.$kind);
        }
    }
}
