<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Mail\CreditsMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Auto top-up (Sprint 15.5b): when credits run low, buy the chosen pack with the saved card — the same path as buying
 * one by hand — within a monthly cap (excl. VAT). Every outcome is emailed and logged: topped up, declined, cap reached,
 * no card. One at a time per business.
 */
final readonly class AutoTopUp
{
    public function __construct(private Payments $payments, private TenantDirectory $tenants, private AuditLog $audit) {}

    /** @return string topped_up | declined | cap | no_card | off */
    public function run(string $tenantId): string
    {
        return (string) Cache::lock('auto-top-up:'.$tenantId, 120)->block(10, fn () => $this->attempt($tenantId));
    }

    public function spentThisMonth(string $tenantId): int
    {
        return (int) DB::table('payments')->where('tenant_id', $tenantId)->where('purpose', 'credits')->where('status', 'succeeded')
            ->where('paid_at', '>=', now()->startOfMonth())->where('details', 'like', '%"auto":true%')->sum('amount_excl_cents');
    }

    private function attempt(string $tenantId): string
    {
        $account = BillingAccount::query()->find($tenantId);
        $pack = $account?->auto_top_up ? CreditPack::query()->whereKey($account->auto_top_up_pack_id)->where('active', true)->first() : null;
        if ($account === null || $pack === null) {
            return 'off';
        }
        $spent = $this->spentThisMonth($tenantId);
        $outcome = match (true) {
            $account->auto_top_up_cap_cents !== null && $spent + $pack->price_cents > $account->auto_top_up_cap_cents => 'cap',
            $this->payments->defaultMethod($tenantId) === null => 'no_card',
            default => null,
        };
        $payment = null;
        if ($outcome === null) {
            $payment = $this->payments->chargeSaved($tenantId, 'credits', $pack->price_cents, ['pack' => $pack->name, 'credits' => $pack->credits, 'auto' => true]);
            $outcome = $payment->status === 'succeeded' ? 'topped_up' : 'declined';
        }
        $rand = fn (int $c) => 'R'.number_format($c / 100, 2);
        [$subject, $lines] = match ($outcome) {
            'topped_up' => ['Credits topped up', ["Your credits ran low, so we added {$pack->credits} credits ({$pack->name}) for {$rand($pack->price_cents)} excl. VAT, charged to your saved card.",
                'Automatic top-ups this month: '.$rand($spent + $pack->price_cents).($account->auto_top_up_cap_cents !== null ? ' of your '.$rand($account->auto_top_up_cap_cents).' limit.' : '.')]],
            'declined' => ['Credits not topped up: card declined', ['Your credits are running low, but your saved card was declined'.($payment?->failure ? ' ('.$payment->failure.')' : '').'.', 'Please update your card or buy credits on your Billing page.']],
            'cap' => ['Credits running low: monthly top-up limit reached', ['Your credits are running low, but another top-up would go over your monthly limit of '.$rand((int) $account->auto_top_up_cap_cents).'.', 'Raise the limit or buy credits on your Billing page.']],
            default => ['Credits running low: no saved card', ['Your credits are running low, but there’s no saved card for automatic top-ups.', 'Add a card or buy credits on your Billing page.']],
        };
        $tenant = $this->tenants->find($tenantId);
        Mail::to($account->billing_email)->send(new CreditsMail($subject, $lines, route('tenant.billing', ['tenant' => $tenant?->handle() ?? ''])));
        $this->audit->record('billing.auto_top_up', 'tenant', $tenantId, ['outcome' => $outcome, 'pack' => $pack->id]);

        return $outcome;
    }
}
