<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Support;

use Wurk\Tenancy\Actions\ShardHealth;
use Wurk\Tenancy\Contracts\ShardStatus;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantMove;

final readonly class ShardStatusReport implements ShardStatus
{
    public function __construct(private ShardHealth $health) {}

    public function shards(): array
    {
        $expected = count($this->health->expected());

        return array_values(Shard::query()->orderBy('name')->get()->map(fn (Shard $s) => [
            'name' => $s->name, 'status' => (string) $s->status, 'businesses' => (int) $s->tenant_count, 'capacity' => (int) $s->capacity,
            'schema_version' => (int) $s->schema_version, 'expected_version' => $expected, 'migrated_at' => $s->migrated_at?->toIso8601String(),
        ])->all());
    }

    public function recentMoves(int $limit = 10): array
    {
        $moves = TenantMove::query()->latest('started_at')->limit($limit)->get();
        $names = Tenant::query()->whereIn('id', $moves->pluck('tenant_id'))->pluck('name', 'id');
        $shards = Shard::query()->pluck('name', 'id');

        return array_values($moves->map(fn (TenantMove $m) => [
            'business' => (string) ($names[$m->tenant_id] ?? $m->tenant_id), 'kind' => $m->kind, 'status' => $m->status,
            'from' => $m->from_shard_id === null ? null : (string) ($shards[$m->from_shard_id] ?? $m->from_shard_id),
            'to' => (string) ($shards[$m->to_shard_id] ?? $m->to_shard_id),
            'rows' => array_sum(array_map('intval', (array) $m->rows)), 'error' => $m->error,
            'started_at' => $m->started_at->toIso8601String(), 'clear_after' => $m->clear_after?->toIso8601String(),
        ])->all());
    }
}
