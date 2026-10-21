<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Wurk\Tenancy\Models\Tenant;

/** WURK staff access to businesses (platform permissions). */
final class TenantPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('tenants.view');
    }

    public function view(Authorizable $user, Tenant $tenant): bool
    {
        return $user->can('tenants.view');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('tenants.manage');
    }

    public function update(Authorizable $user, Tenant $tenant): bool
    {
        return $user->can('tenants.manage');
    }

    public function suspend(Authorizable $user, Tenant $tenant): bool
    {
        return $user->can('tenants.manage');
    }
}
