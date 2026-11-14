<?php

declare(strict_types=1);

namespace Wurk\Data\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Daily: what was deleted more than 30 days ago is removed for good (Sprint 16.6). */
final class PurgeDeletedCommand extends Command
{
    protected $signature = 'data:purge-deleted';

    protected $description = 'Remove for good what was deleted more than 30 days ago';

    public function handle(TenantDirectory $tenants, TenantContext $context, RecentlyDeleted $deleted): int
    {
        $before = now()->subDays(RecentlyDeleted::DAYS);
        $n = 0;
        foreach ($tenants->activeIds() as $id) {
            $tenant = $tenants->find($id);
            if ($tenant === null) {
                continue;
            }
            $n += $context->run($tenant, function () use ($deleted, $before): int {
                $done = 0;
                foreach ($deleted->types() as $t) {
                    try {
                        $done += (int) ($t['purge'])($before);
                    } catch (Throwable $e) {
                        report($e); // one kind failing never stops the rest
                    }
                }

                return $done;
            });
        }
        $this->info("Removed {$n} for good.");

        return self::SUCCESS;
    }
}
