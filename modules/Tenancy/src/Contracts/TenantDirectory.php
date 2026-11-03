<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** Read-only lookup of businesses for other modules (e.g. "choose a business"). */
interface TenantDirectory
{
    public function find(string $tenantId): ?CurrentTenant;

    public function findByHandle(string $handle): ?CurrentTenant;

    /** @return array{active: int, suspended: int, provisioning: int, shards: int, capacity: int, used: int} */
    public function summary(): array;

    /**
     * Connection names of every registered shard (for maintenance commands that
     * work across all tenants on a shard at once).
     *
     * @return list<string>
     */
    public function shardConnections(): array;

    /**
     * @param  list<string>  $tenantIds
     * @return list<CurrentTenant> active tenants only, sorted by name
     */
    public function activeAmong(array $tenantIds): array;

    /**
     * Every active business's id (for maintenance commands).
     *
     * @return list<string>
     */
    public function activeIds(): array;
}
