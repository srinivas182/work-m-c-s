<?php

declare(strict_types=1);

namespace Wurk\Access\Actions;

use Wurk\Access\AccessManager;
use Wurk\Access\Events\AccessChanged;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\PlatformRoleAssignment;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Roles\SystemRoles;

final readonly class PlatformAccess
{
    public function __construct(private AccessManager $access) {}

    public function grant(string $identityId, string $roleKey): void
    {
        $valid = SystemRoles::exists($roleKey)
            ? SystemRoles::scope($roleKey) === Permission::PLATFORM
            : str_starts_with($roleKey, 'custom:') && PlatformRole::query()->whereKey(substr($roleKey, 7))->exists();

        if (! $valid) {
            throw new AccessRuleViolation("[{$roleKey}] is not a WURK staff role.");
        }

        $assignment = PlatformRoleAssignment::query()->firstOrCreate(['identity_id' => $identityId, 'role_key' => $roleKey]);
        if ($assignment->wasRecentlyCreated) {
            event(new AccessChanged('platform_role.granted', 'identity', $identityId, ['role' => $roleKey], platform: true));
        }
        $this->access->flush();
    }

    public function revoke(string $identityId, string $roleKey): void
    {
        if ($roleKey === SystemRoles::SUPER_ADMIN
            && PlatformRoleAssignment::query()->where('role_key', SystemRoles::SUPER_ADMIN)->count() <= 1
            && PlatformRoleAssignment::query()->where('identity_id', $identityId)->where('role_key', SystemRoles::SUPER_ADMIN)->exists()) {
            throw new AccessRuleViolation('WURK must always have at least one super admin.');
        }

        PlatformRoleAssignment::query()->where('identity_id', $identityId)->where('role_key', $roleKey)->delete();
        event(new AccessChanged('platform_role.revoked', 'identity', $identityId, ['role' => $roleKey], platform: true));
        $this->access->flush();
    }
}
