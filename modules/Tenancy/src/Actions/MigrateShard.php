<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantMigrationPaths;

/**
 * Runs tenant migrations on one shard and records its schema version
 * (the number of tenant migrations applied).
 */
final readonly class MigrateShard
{
    public function __construct(
        private ShardConnections $connections,
        private TenantMigrationPaths $paths,
        private Kernel $artisan,
    ) {}

    public function __invoke(Shard $shard, bool $fresh = false): int
    {
        $connection = $this->connections->register($shard);

        $exit = $this->artisan->call($fresh ? 'migrate:fresh' : 'migrate', [
            '--database' => $connection,
            '--path' => $this->paths->all(),
            '--realpath' => true,
            '--force' => true,
        ]);

        if ($exit !== 0) {
            throw new RuntimeException("Migrations failed on shard [{$shard->name}]: ".trim($this->artisan->output()));
        }

        $version = DB::connection($connection)->table('migrations')->count();
        $shard->forceFill(['schema_version' => $version, 'migrated_at' => now()])->save();

        return $version;
    }
}
