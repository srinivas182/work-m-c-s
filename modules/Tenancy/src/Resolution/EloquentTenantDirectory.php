<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Resolution;

use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Sharding\ShardConnections;

final class EloquentTenantDirectory implements TenantDirectory
{
    public function find(string $tenantId): ?CurrentTenant
    {
        return Tenant::query()->find($tenantId);
    }

    public function findByHandle(string $handle): ?CurrentTenant
    {
        return Tenant::query()->where('handle', strtolower($handle))->first();
    }

    public function summary(): array
    {
        $counts = Tenant::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'active' => (int) ($counts['active'] ?? 0),
            'suspended' => (int) ($counts['suspended'] ?? 0),
            'provisioning' => (int) ($counts['provisioning'] ?? 0),
            'shards' => Shard::query()->count(),
            'capacity' => (int) Shard::query()->where('status', Shard::STATUS_ACTIVE)->sum('capacity'),
            'used' => (int) Shard::query()->sum('tenant_count'),
        ];
    }

    public function shardConnections(): array
    {
        $connections = app(ShardConnections::class);

        return array_values(Shard::query()->orderBy('name')->get()->map(fn (Shard $s) => $connections->register($s))->all());
    }

    public function activeAmong(array $tenantIds): array
    {
        return array_values(Tenant::query()->whereIn('id', $tenantIds)
            ->where('status', Tenant::STATUS_ACTIVE)->orderBy('name')->get()->all());
    }

    public function activeIds(): array
    {
        return array_values(array_map('strval', Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->orderBy('id')->pluck('id')->all()));
    }
}
