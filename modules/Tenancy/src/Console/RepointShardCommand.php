<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Actions\ShardHealth;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Sharding\ShardConnections;

/**
 * Sprint 18.4c: point a shard at another database — after restoring a whole shard from a backup (docs/runbooks/restore.md).
 * The new database is health-checked first; if it isn't healthy, the shard stays where it is.
 */
final class RepointShardCommand extends Command
{
    protected $signature = 'shards:repoint {name : The shard}
        {--driver=mysql : mysql or sqlite}
        {--host=}
        {--port=}
        {--database= : Database name (or file path for sqlite)}
        {--username=}
        {--password=}
        {--force : Don’t ask}';

    protected $description = 'Point a shard at another database (after restoring it from a backup)';

    public function handle(ShardHealth $health, ShardConnections $connections): int
    {
        $shard = Shard::query()->where('name', (string) $this->argument('name'))->first();
        if ($shard === null) {
            $this->components->error('No shard with that name.');

            return self::FAILURE;
        }
        $old = $shard->connection;
        $new = (string) $this->option('driver') === 'sqlite'
            ? ['driver' => 'sqlite', 'database' => (string) $this->option('database'), 'foreign_key_constraints' => true]
            : array_filter([...$old, 'driver' => (string) $this->option('driver'), 'host' => $this->option('host') ?? ($old['host'] ?? null), 'port' => $this->option('port') ?? ($old['port'] ?? null),
                'database' => $this->option('database'), 'username' => $this->option('username') ?? ($old['username'] ?? null), 'password' => $this->option('password') ?? ($old['password'] ?? null)],
                fn ($v) => $v !== null && $v !== '');

        // Check the new database before switching: a candidate copy of the shard, never saved unless healthy.
        $candidate = $shard->replicate();
        $candidate->id = $shard->id.'x';
        $candidate->connection = $new;
        $problem = $health->problem($candidate);
        DB::purge(ShardConnections::nameFor($candidate->id));
        if ($problem !== null) {
            $this->components->error("Not switched — the new database isn’t healthy: {$problem}");

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Point {$shard->name} ({$shard->tenant_count} businesses) at the new database?", false)) {
            return self::FAILURE;
        }
        $shard->forceFill(['connection' => $new])->save();
        DB::purge(ShardConnections::nameFor($shard->id));
        $connections->register($shard);
        Artisan::call('queue:restart');
        $this->components->info("{$shard->name} now uses the new database. Queue workers restart by themselves; reload the web servers (php artisan octane:reload, or redeploy) so they connect to it too.");

        return self::SUCCESS;
    }
}
