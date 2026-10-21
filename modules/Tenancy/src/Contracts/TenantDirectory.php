<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** Read-only lookup of businesses for other modules (e.g. "choose a business"). */
interface TenantDirectory
{
    public function find(string $tenantId): ?CurrentTenant;

    public function findByHandle(string $handle): ?CurrentTenant;

    /**
     * @param  list<string>  $tenantIds
     * @return list<CurrentTenant> active tenants only, sorted by name
     */
    public function activeAmong(array $tenantIds): array;
}
