<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;
use Wurk\Tenancy\Actions\MigrateShard;
use Wurk\Tenancy\Actions\ShardHealth;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantMigrationPaths;

/**
 * Rolls tenant migrations out shard by shard (ADR-0006). Sprint 18.4b: the **canary** shard goes first and is
 * health-checked before any other is touched; every shard is health-checked after its migrations. On a failure the
 * rollout stops, and the migrations this run applied to that shard are rolled back — so a bad migration reaches one
 * shard at most, and leaves it as it was.
 */
final class MigrateShardsCommand extends Command
{
    protected $signature = 'shards:migrate
        {--shard=* : Only these shard names}
        {--canary : Only the canary shard (then run again without it for the rest)}
        {--no-rollback : On a failure, leave the shard as the failed run left it}';

    protected $description = 'Run pending tenant migrations on every shard — the canary first — one shard at a time';

    public function handle(MigrateShard $migrate, ShardHealth $health, ShardConnections $connections, TenantMigrationPaths $paths, Kernel $artisan): int
    {
        $shards = $this->shards();
        if ($shards === []) {
            $this->components->warn('No shards to migrate.');

            return self::SUCCESS;
        }

        foreach ($shards as $i => $shard) {
            $label = $shard->name.($i === 0 && $this->option('shard') === [] ? ' (canary)' : '');
            $connection = $connections->register($shard);
            $before = null;
            try {
                $before = $this->lastBatch($connection);
                $version = $migrate($shard);
                $problem = $health->problem($shard);
                if ($problem !== null) {
                    throw new RuntimeException("Health check failed: {$problem}");
                }
                $this->components->twoColumnDetail($label, "<fg=green>schema {$version} · healthy</>");
            } catch (Throwable $e) {
                $this->components->twoColumnDetail($label, '<fg=red>FAILED</>');
                $this->components->error($e->getMessage());
                if (! $this->option('no-rollback') && $before !== null && $this->lastBatch($connection) > $before) {
                    $artisan->call('migrate:rollback', ['--database' => $connection, '--path' => $paths->all(), '--realpath' => true, '--force' => true]);
                    $shard->forceFill(['schema_version' => (int) DB::connection($connection)->table('migrations')->count()])->save();
                    $this->components->warn("This run’s migrations on {$shard->name} were rolled back.");
                }
                $this->components->warn('Rollout stopped. Remaining shards were not migrated.');

                return self::FAILURE;
            }
            if ($this->option('canary')) {
                $this->components->info('Canary migrated and healthy. Watch it, then run shards:migrate for the rest.');

                return self::SUCCESS;
            }
        }

        return self::SUCCESS;
    }

    /** @return list<Shard> the canary (WURK_CANARY_SHARD, else the first by name) first */
    private function shards(): array
    {
        $query = Shard::query()->orderBy('name');
        $only = array_filter((array) $this->option('shard'));
        if ($only !== []) {
            $query->whereIn('name', $only);
        }
        $shards = $query->get()->all();
        $canary = (string) config('wurk.tenancy.canary_shard', '');
        usort($shards, fn (Shard $a, Shard $b) => ($b->name === $canary) <=> ($a->name === $canary));

        return $shards;
    }

    private function lastBatch(string $connection): int
    {
        return Schema::connection($connection)->hasTable('migrations') ? (int) DB::connection($connection)->table('migrations')->max('batch') : 0;
    }
}
