<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Policies;

use Illuminate\Contracts\Auth\Access\Authorizable;
use Wurk\Tenancy\Models\Branch;

/**
 * Branches of the current business (business permissions). The model is
 * tenant-scoped, so another business's branch can never even be loaded.
 */
final class BranchPolicy
{
    public function viewAny(Authorizable $user): bool
    {
        return $user->can('branches.view');
    }

    public function view(Authorizable $user, Branch $branch): bool
    {
        return $user->can('branches.view');
    }

    public function create(Authorizable $user): bool
    {
        return $user->can('branches.manage');
    }

    public function update(Authorizable $user, Branch $branch): bool
    {
        return $user->can('branches.manage');
    }

    public function delete(Authorizable $user, Branch $branch): bool
    {
        return $user->can('branches.manage') && ! $branch->is_default;
    }
}
