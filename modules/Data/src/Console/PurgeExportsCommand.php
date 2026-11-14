<?php

declare(strict_types=1);

namespace Wurk\Data\Console;

use Illuminate\Console\Command;
use Wurk\Data\Models\DataExport;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Hourly: deletes exports older than 24 hours (Sprint 16.6). */
final class PurgeExportsCommand extends Command
{
    protected $signature = 'data:purge-exports';

    protected $description = 'Delete full exports after their 24 hours';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $n = 0;
        foreach ($tenants->activeIds() as $id) {
            $tenant = $tenants->find($id);
            if ($tenant === null) {
                continue;
            }
            $n += $context->run($tenant, function () use ($context): int {
                $done = 0;
                foreach (DataExport::query()->where('status', 'ready')->where('expires_at', '<', now())->get() as $e) {
                    if ($e->path !== null) {
                        $context->disk()->delete($e->path);
                    }
                    $e->update(['status' => 'expired', 'path' => null]);
                    $done++;
                }

                return $done;
            });
        }
        $this->info("Deleted {$n} expired exports.");

        return self::SUCCESS;
    }
}
