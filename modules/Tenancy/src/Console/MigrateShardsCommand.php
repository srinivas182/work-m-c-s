<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Tenancy\Actions\MigrateShard;
use Wurk\Tenancy\Models\Shard;

/**
 * Rolls tenant migrations out shard by shard and stops at the first failure,
 * so a bad migration never reaches every shard (ADR-0006).
 */
final class MigrateShardsCommand extends Command
{
    protected $signature = 'shards:migrate {--shard=* : Only these shard names}';

    protected $description = 'Run pending tenant migrations on every shard, one shard at a time';

    public function handle(MigrateShard $migrate): int
    {
        $query = Shard::query()->orderBy('name');
        $only = array_filter((array) $this->option('shard'));
        if ($only !== []) {
            $query->whereIn('name', $only);
        }

        foreach ($query->get() as $shard) {
            try {
                $version = $migrate($shard);
                $this->components->twoColumnDetail($shard->name, "<fg=green>schema {$version}</>");
            } catch (Throwable $e) {
                $this->components->twoColumnDetail($shard->name, '<fg=red>FAILED</>');
                $this->components->error($e->getMessage());
                $this->components->warn('Rollout stopped. Remaining shards were not migrated.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
