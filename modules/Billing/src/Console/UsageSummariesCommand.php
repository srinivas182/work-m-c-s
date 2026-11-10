<?php

declare(strict_types=1);

namespace Wurk\Billing\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Wurk\Billing\Mail\CreditsMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\UsageHistory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Hourly: on the 1st, from 08:00 in each business's time, last month's credit use by channel, what's left, and any
 * automatic top-ups — once a month, to the billing email; owners can switch it off (Sprint 15.5b).
 */
final class UsageSummariesCommand extends Command
{
    protected $signature = 'billing:usage-summaries';

    protected $description = 'Email each business its monthly credit usage summary when due';

    public function handle(TenantDirectory $tenants, TenantContext $context, UsageHistory $usage): int
    {
        $sent = 0;
        foreach (BillingAccount::query()->where('usage_summary', true)->get() as $account) {
            $tenant = $tenants->find($account->tenant_id);
            if ($tenant === null || $tenant->isDemo() || ! $tenant->isActive()) {
                continue;
            }
            $local = CarbonImmutable::now($tenant->timezone());
            $month = $local->startOfMonth();
            if ($local->day !== 1 || $local->hour < 8 || $account->usage_summary_for === $month->toDateString()) {
                continue;
            }
            $from = $month->subMonthNoOverflow();
            $used = $usage->between($from, $month->subDay())[$account->tenant_id] ?? [];
            $balance = (int) $context->run($tenant, fn () => app(CreditWallet::class)->balance()['balance_milli']);
            $topUps = DB::table('payments')->where('tenant_id', $account->tenant_id)->where('purpose', 'credits')->where('status', 'succeeded')
                ->whereBetween('paid_at', [$from->setTimezone((string) config('app.timezone')), $month->setTimezone((string) config('app.timezone'))])->where('details', 'like', '%"auto":true%')
                ->selectRaw('count(*) as n, coalesce(sum(amount_excl_cents), 0) as cents')->first();
            $names = ['sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'email' => 'Email', 'ai' => 'AI assistant'];
            $lines = ['Your credits for '.$from->format('F Y').':', ''];
            foreach ($used as $channel => $u) {
                $lines[] = '  '.($names[$channel] ?? ucfirst((string) $channel)).': '.number_format($u['credits_milli'] / 1000, 0, '.', ' ').' credits ('.$u['messages'].' '.($u['messages'] === 1 ? 'message' : 'messages').')';
            }
            if ($used === []) {
                $lines[] = '  No credits used.';
            }
            $lines[] = '';
            $lines[] = 'Credits left now: '.number_format($balance / 1000, 0, '.', ' ').'.';
            if ((int) ($topUps->n ?? 0) > 0) {
                $lines[] = 'Automatic top-ups: '.(int) ($topUps->n ?? 0).' (R'.number_format(((int) ($topUps->cents ?? 0)) / 100, 2).' excl. VAT).';
            }
            $lines[] = '';
            $lines[] = 'You can stop this email on your Billing page.';
            Mail::to($account->billing_email)->send(new CreditsMail("Your credits for {$from->format('F Y')}", $lines, route('tenant.billing', ['tenant' => $tenant->handle()])));
            BillingAccount::query()->whereKey($account->tenant_id)->update(['usage_summary_for' => $month->toDateString()]);
            $sent++;
        }
        $this->info("Sent {$sent} usage summaries.");

        return self::SUCCESS;
    }
}
