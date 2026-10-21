<?php

declare(strict_types=1);

namespace Wurk\Credits\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Daily: grants each business its package's monthly credits on its billing
 * anniversary (the 31st falls back to the month's last day). Safe to run any
 * number of times: one grant per business per month, by idempotency key.
 */
final class GrantAllowancesCommand extends Command
{
    protected $signature = 'credits:grant-allowances {--date= : Pretend today is this date (YYYY-MM-DD)}';

    protected $description = 'Grant monthly WURK Credits allowances due today';

    public function handle(TenantDirectory $directory, TenantContext $context, Entitlements $entitlements, CreditWallet $wallet): int
    {
        $today = $this->option('date') ? Carbon::parse((string) $this->option('date')) : now();
        $granted = 0;

        foreach ($entitlements->subscribedSince() as $tenantId => $startedAt) {
            $anniversaryDay = min(Carbon::parse($startedAt)->day, $today->daysInMonth);
            $credits = $entitlements->limits($tenantId)['monthly_credits'] ?? 0;
            $tenant = $directory->find($tenantId);

            if ($today->day !== $anniversaryDay || $credits <= 0 || $tenant === null || ! $tenant->isActive()) {
                continue;
            }

            $context->run($tenant, fn () => $wallet->grant('allowance', $credits * 1000, 'allowance:'.$today->format('Y-m'), "Monthly allowance ({$credits} credits)"));
            $granted++;
        }

        $this->components->info("Allowances granted: {$granted}.");

        return self::SUCCESS;
    }
}
