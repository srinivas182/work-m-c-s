<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Tenancy\Actions\TenantMoves;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;

/** Sprint 18.4a: move one business to another shard (e.g. to even out a busy one). */
final class MoveTenantCommand extends Command
{
    protected $signature = 'tenants:move {handle : The business} {--to= : The shard to move it to (its name)} {--force : Don’t ask}';

    protected $description = 'Move a business to another shard: pause, copy, compare, switch (the old copy is kept 7 days)';

    public function handle(TenantMoves $moves): int
    {
        $tenant = Tenant::query()->where('handle', (string) $this->argument('handle'))->first();
        $to = Shard::query()->where('name', (string) $this->option('to'))->first();
        if ($tenant === null || $to === null) {
            $this->components->error($tenant === null ? 'No business with that address.' : 'No shard with that name (see shards:register).');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Move {$tenant->name} to {$to->name}? It pauses for a few minutes while it copies.", true)) {
            return self::FAILURE;
        }
        try {
            $move = $moves->move($tenant, $to);
        } catch (Throwable $e) {
            $this->components->error('Not moved: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->components->info("Moved {$tenant->name} to {$to->name}: ".array_sum((array) $move->rows).' rows, copies identical. The old copy is removed after '.TenantMoves::KEEP_DAYS.' days.');

        return self::SUCCESS;
    }
}
