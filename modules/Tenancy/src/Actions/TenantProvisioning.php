<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Actions;

use Illuminate\Support\Facades\DB;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantDomain;
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

    public function markDemo(string $tenantId, bool $demo): void
    {
        Tenant::query()->findOrFail($tenantId)->forceFill(['is_demo' => $demo])->save();
    }

    public function archive(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId): void {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenantId);
            $new = 'archived-'.strtolower($tenant->id);
            $tenant->domains()->where('type', TenantDomain::TYPE_SUBDOMAIN)->update(['host' => $new.'.'.config('wurk.domains.root')]);
            $tenant->forceFill(['handle' => $new, 'status' => Tenant::STATUS_DELETED])->save(); // the model clears the old handle from the cache
        });
    }
}
