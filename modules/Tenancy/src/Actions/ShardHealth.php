<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantMigrationPaths;
use Wurk\Tenancy\Support\TenantRows;

/**
 * Is a shard healthy after its migrations (Sprint 18.4b)? It answers, it has every tenant migration WURK ships, and every
 * business table can be read. Run on the canary shard before the rest are migrated, and on each shard after.
 */
final readonly class ShardHealth
{
    public function __construct(private ShardConnections $connections, private TenantMigrationPaths $paths, private TenantRows $rows) {}

    /** Null when healthy, otherwise what's wrong. */
    public function problem(Shard $shard): ?string
    {
        $connection = $this->connections->register($shard);
        try {
            $db = DB::connection($connection);
            $db->select('select 1');
            if (! Schema::connection($connection)->hasTable('migrations')) {
                return 'It has never been migrated.';
            }
            $missing = array_diff($this->expected(), $db->table('migrations')->pluck('migration')->all());
            if ($missing !== []) {
                return count($missing).' migration(s) not applied, e.g. '.reset($missing).'.';
            }
            foreach ($this->rows->tables($connection) as $table) {
                $db->table($table)->limit(1)->get();
            }
        } catch (Throwable $e) {
            return mb_substr($e->getMessage(), 0, 300);
        }

        return null;
    }

    /** @return list<string> every tenant migration WURK ships (file names without .php) */
    public function expected(): array
    {
        $names = [];
        foreach ($this->paths->all() as $path) {
            foreach (glob($path.'/*.php') ?: [] as $file) {
                $names[] = basename($file, '.php');
            }
        }
        sort($names);

        return $names;
    }
}
