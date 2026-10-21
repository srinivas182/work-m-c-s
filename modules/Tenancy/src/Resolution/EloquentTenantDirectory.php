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
}
