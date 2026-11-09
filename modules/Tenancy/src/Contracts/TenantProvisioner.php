<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** Creating businesses: web-address checks and provisioning on the least-full shard. */
interface TenantProvisioner
{
    /** Why a handle can't be used ("Already taken", "Reserved word"…), or null if it's free. */
    public function handleProblem(string $handle): ?string;

    public function provision(string $name, string $handle, string $country): CurrentTenant;

    /** Mark or unmark a business as an agent's demo (Sprint 14.8). */
    public function markDemo(string $tenantId, bool $demo): void;

    /** Archive a business: site offline, address freed (handle and subdomain become archived-<id>), status deleted. */
    public function archive(string $tenantId): void;
}
