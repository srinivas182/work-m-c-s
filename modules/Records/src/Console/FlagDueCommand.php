<?php

declare(strict_types=1);

namespace Wurk\Records\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Daily: flag patient records past the retention period, for the owner to review. Deletes nothing. Reads
 * the central list, so only businesses with records due are visited.
 */
final class FlagDueCommand extends Command
{
    protected $signature = 'records:flag-due';

    protected $description = 'Flag patient records past the retention period (nothing is deleted)';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $cutoff = now()->subYears((int) config('records.retention_years', 6));
        $n = 0;
        foreach (DB::table('record_retention')->where('last_document_at', '<', $cutoff)->distinct()->pluck('tenant_id') as $tenantId) {
            $tenant = $tenants->find((string) $tenantId);
            if ($tenant !== null) {
                $n += (int) $context->run($tenant, fn () => app(Records::class)->flagDue());
            }
        }
        $this->info("{$n} patient(s) flagged.");

        return self::SUCCESS;
    }
}
