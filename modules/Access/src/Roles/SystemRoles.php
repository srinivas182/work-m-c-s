<?php

declare(strict_types=1);

namespace Wurk\Access\Roles;

use Wurk\Access\Permissions\Permission;

/**
 * Built-in roles, defined in code so every business always has the current
 * definitions (ADR-0009). "*" means every permission in that scope.
 */
final class SystemRoles
{
    public const SUPER_ADMIN = 'super-admin';

    public const OWNER = 'owner';

    public const BRANCH_MANAGER = 'branch-manager';

    public const STAFF = 'staff';

    /** @var array<string, array{name: string, scope: string, permissions: list<string>, branch_restricted?: bool}> */
    public const DEFINITIONS = [
        // Platform (WURK staff)
        self::SUPER_ADMIN => ['name' => 'Super admin', 'scope' => Permission::PLATFORM, 'permissions' => ['*']],
        'finance' => ['name' => 'Finance', 'scope' => Permission::PLATFORM, 'permissions' => ['tenants.view']],
        'support' => ['name' => 'Support', 'scope' => Permission::PLATFORM, 'permissions' => ['tenants.view', 'platform-audit.view']],
        'managed-services' => ['name' => 'Managed services', 'scope' => Permission::PLATFORM, 'permissions' => ['tenants.view']],

        // Business
        self::OWNER => ['name' => 'Owner', 'scope' => Permission::BUSINESS, 'permissions' => ['*']],
        self::BRANCH_MANAGER => [
            'name' => 'Branch manager',
            'scope' => Permission::BUSINESS,
            'permissions' => ['branches.view', 'team.view'],
            'branch_restricted' => true,
        ],
        self::STAFF => ['name' => 'Staff', 'scope' => Permission::BUSINESS, 'permissions' => ['branches.view']],
    ];

    public static function exists(string $key): bool
    {
        return isset(self::DEFINITIONS[$key]);
    }

    public static function scope(string $key): ?string
    {
        return self::DEFINITIONS[$key]['scope'] ?? null;
    }

    /** @return list<string> keys of system roles in a scope */
    public static function keys(string $scope): array
    {
        return array_keys(array_filter(self::DEFINITIONS, fn (array $d) => $d['scope'] === $scope));
    }
}
