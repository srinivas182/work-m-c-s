<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** Creating businesses: web-address checks and provisioning on the least-full shard. */
interface TenantProvisioner
{
    /** Why a handle can't be used ("Already taken", "Reserved word"…), or null if it's free. */
    public function handleProblem(string $handle): ?string;

    public function provision(string $name, string $handle, string $country): CurrentTenant;
}
