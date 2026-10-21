<?php

declare(strict_types=1);

namespace Wurk\Access\Actions;

use Illuminate\Validation\ValidationException;
use Wurk\Access\AccessManager;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\MemberRole;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\PlatformRoleAssignment;
use Wurk\Access\Models\Role;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;

/**
 * Custom roles built from declared permissions. Business roles can only hold
 * business permissions; platform roles only platform permissions.
 */
final readonly class CustomRoles
{
    public function __construct(private PermissionRegistry $registry, private AccessManager $access) {}

    /** @param  list<string>  $permissions */
    public function createBusinessRole(string $name, array $permissions): Role
    {
        return Role::query()->create(['name' => $name, 'permissions' => $this->validated($permissions, Permission::BUSINESS)]);
    }

    /** @param  list<string>  $permissions */
    public function updateBusinessRole(Role $role, string $name, array $permissions): Role
    {
        $role->update(['name' => $name, 'permissions' => $this->validated($permissions, Permission::BUSINESS)]);
        $this->access->flush();

        return $role;
    }

    public function deleteBusinessRole(Role $role): void
    {
        if (MemberRole::query()->where('role_key', $role->key())->exists()) {
            throw new AccessRuleViolation('This role is still assigned to team members. Reassign them first.');
        }
        $role->delete();
    }

    /** @param  list<string>  $permissions */
    public function createPlatformRole(string $name, array $permissions): PlatformRole
    {
        return PlatformRole::query()->create(['name' => $name, 'permissions' => $this->validated($permissions, Permission::PLATFORM)]);
    }

    public function deletePlatformRole(PlatformRole $role): void
    {
        if (PlatformRoleAssignment::query()->where('role_key', $role->key())->exists()) {
            throw new AccessRuleViolation('This role is still assigned to WURK staff. Reassign them first.');
        }
        $role->delete();
    }

    /**
     * @param  list<string>  $permissions
     * @return list<string>
     */
    private function validated(array $permissions, string $scope): array
    {
        $allowed = $this->registry->keys($scope);
        $invalid = array_values(array_diff($permissions, $allowed));

        if ($invalid !== []) {
            throw ValidationException::withMessages(['permissions' => 'These permissions can’t be used here: '.implode(', ', $invalid).'.']);
        }

        $clean = array_values(array_unique($permissions));
        sort($clean);

        return $clean;
    }
}
