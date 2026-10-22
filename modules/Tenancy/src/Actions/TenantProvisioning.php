<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Support\HandleRules;

final readonly class TenantProvisioning implements TenantProvisioner
{
    public function __construct(private CreateTenant $create) {}

    public function handleProblem(string $handle): ?string
    {
        $handle = strtolower(trim($handle));

        return HandleRules::problem($handle) ?? (Tenant::query()->where('handle', $handle)->exists() ? 'That name is already taken.' : null);
    }

    public function provision(string $name, string $handle, string $country): CurrentTenant
    {
        return ($this->create)($name, strtolower(trim($handle)), $country);
    }
}
