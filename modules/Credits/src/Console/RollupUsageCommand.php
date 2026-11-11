<?php

declare(strict_types=1);

namespace Wurk\Credits\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Nightly: each business's credit use per channel per day, into one central table (Sprint 15.4b). Safe to re-run;
 * --since backfills from the ledgers' history (run once after deploying).
 */
final class RollupUsageCommand extends Command
{
    protected $signature = 'credits:rollup-usage {--since= : first day to roll up (default: yesterday)}';

    protected $description = 'Roll up credit usage per business and channel into the central table';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $from = CarbonImmutable::parse((string) ($this->option('since') ?: now()->subDay()->toDateString()))->startOfDay();
        $rows = 0;
        foreach ($tenants->activeIds() as $id) {
            $tenant = $tenants->find($id);
            if ($tenant === null || $tenant->isDemo()) {
                continue;
            }
            $rows += (int) $context->run($tenant, function () use ($context, $from): int {
                $used = DB::connection($context->connectionName())->table('credit_ledger')->where('tenant_id', $context->id())->where('type', 'debit')
                    ->where('created_at', '>=', $from)->whereNotNull('channel')
                    ->selectRaw('date(created_at) as day, channel, sum(-amount_milli) as used, count(*) as messages')->groupBy('day', 'channel')->get();
                foreach ($used as $u) {
                    DB::table('credit_usage_daily')->updateOrInsert(['day' => (string) $u->day, 'tenant_id' => $context->id(), 'channel' => (string) $u->channel], ['used_milli' => max(0, (int) $u->used), 'messages' => (int) $u->messages]);
                }

                return $used->count();
            });
        }
        $this->info("Rolled up {$rows} days of usage.");

        return self::SUCCESS;
    }
}
