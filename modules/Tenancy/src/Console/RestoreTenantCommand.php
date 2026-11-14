<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Tenancy\Actions\TenantMoves;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;

/**
 * Sprint 18.4a: restore one business from a backup — first restore the shard's backup (to the point in time) as a separate
 * database, point the "restore" connection at it (RESTORE_DB_*), then run this. See docs/runbooks/restore.md.
 */
final class RestoreTenantCommand extends Command
{
    protected $signature = 'tenants:restore {handle : The business}
        {--from=restore : The database connection holding the backup}
        {--to= : A different shard to restore it onto (its current rows are kept 7 days)}
        {--replace : Restore onto its own shard, replacing what it has now (saved to a file first)}
        {--force : Don’t ask}';

    protected $description = 'Restore one business from a backup database onto a shard';

    public function handle(TenantMoves $moves): int
    {
        $tenant = Tenant::query()->where('handle', (string) $this->argument('handle'))->first();
        if ($tenant === null) {
            $this->components->error('No business with that address.');

            return self::FAILURE;
        }
        $to = $this->option('to') ? Shard::query()->where('name', (string) $this->option('to'))->first() : null;
        if ($this->option('to') && $to === null) {
            $this->components->error('No shard with that name.');

            return self::FAILURE;
        }
        $what = $to === null ? 'replacing what it has now (saved to a file first)' : "onto {$to->name} (its current rows are kept for ".TenantMoves::KEEP_DAYS.' days)';
        if (! $this->option('force') && ! $this->confirm("Restore {$tenant->name} from the backup, {$what}?", false)) {
            return self::FAILURE;
        }
        try {
            $move = $moves->restore($tenant, (string) $this->option('from'), $to, (bool) $this->option('replace'));
        } catch (Throwable $e) {
            $this->components->error('Not restored: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("Restored {$tenant->name}: ".array_sum((array) $move->rows).' rows, identical to the backup.');

        return self::SUCCESS;
    }
}
