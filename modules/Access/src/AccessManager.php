<?php

declare(strict_types=1);

namespace Wurk\Access;

use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Access\Models\Member;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\PlatformRoleAssignment;
use Wurk\Access\Models\Role;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Answers "may this person do X here?".
 *
 * Platform permissions come from platform roles. Business permissions come
 * from the person's roles in the *current* business only: without a business
 * context, or in a business they do not belong to, the answer is always no.
 * Results are memoised per request and cleared when the context changes.
 */
final class AccessManager implements BranchRestriction
{
    /** @var array<string, list<string>> */
    private array $memo = [];

    public function __construct(
        private readonly PermissionRegistry $registry,
        private readonly TenantContext $context,
        private readonly PeopleDirectory $people,
    ) {}

    public function allows(string $identityId, string $permission): bool
    {
        $definition = $this->registry->get($permission);

        if ($definition === null) {
            return false;
        }

        $granted = $definition->scope === Permission::PLATFORM
            ? $this->platformPermissions($identityId)
            : $this->businessPermissions($identityId);

        return in_array($permission, $granted, true);
    }

    /** @return list<string> */
    public function platformPermissions(string $identityId): array
    {
        if (! $this->people->isActiveStaff($identityId)) {
            return $this->memo["p:{$identityId}"] = [];
        }

        return $this->memo["p:{$identityId}"] ??= $this->expand(
            array_values(array_map('strval', PlatformRoleAssignment::query()->where('identity_id', $identityId)->pluck('role_key')->all())),
            Permission::PLATFORM,
        );
    }

    /** @return list<string> */
    public function businessPermissions(string $identityId): array
    {
        if (! $this->context->has()) {
            return [];
        }

        $key = 'b:'.$this->context->id().":{$identityId}";
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $member = $this->member($identityId);
        if ($member === null) {
            return $this->memo[$key] = $this->supportPermissions($identityId);
        }

        return $this->memo[$key] = $this->expand($member->roleKeys(), Permission::BUSINESS);
    }

    /**
     * Branch ids a person is limited to in the current business, or null for
     * no restriction (owners, unrestricted members, or no business context).
     *
     * @return list<string>|null
     */
    public function branchRestriction(string $identityId): ?array
    {
        if (! $this->context->has()) {
            return null;
        }

        $member = $this->member($identityId);
        if ($member === null) {
            // WURK support with the owner's consent sees every branch; anyone else not on the team sees nothing.
            return app(SupportSessions::class)->active($this->context->id(), $identityId) !== null ? null : [];
        }

        if ($member->all_branches || in_array(SystemRoles::OWNER, $member->roleKeys(), true)) {
            return null;
        }

        return array_values(array_map('strval', $member->branches()->pluck('branch_id')->all()));
    }

    /**
     * WURK staff let in by the owner (Sprint 16.4) can open the same screens as the business — except, always, money,
     * who has access, security and health records. Whether they may change anything (view-only refuses every change;
     * full refuses sensitive ones) is enforced on each request by the Support module's session guard.
     *
     * @return list<string>
     */
    private function supportPermissions(string $identityId): array
    {
        if (app(SupportSessions::class)->active($this->context->id(), $identityId) === null) {
            return [];
        }

        return array_values(array_diff($this->registry->keys(Permission::BUSINESS), SupportSessions::NEVER));
    }

    public function member(string $identityId): ?Member
    {
        return Member::query()->where('identity_id', $identityId)->where('status', 'active')->first();
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<string>
     */
    private function expand(array $roleKeys, string $scope): array
    {
        $all = $this->registry->keys($scope);
        $granted = [];

        foreach ($roleKeys as $roleKey) {
            if (SystemRoles::exists($roleKey)) {
                if (SystemRoles::scope($roleKey) !== $scope) {
                    continue;
                }
                $list = SystemRoles::DEFINITIONS[$roleKey]['permissions'];
            } elseif (str_starts_with($roleKey, 'custom:')) {
                $id = substr($roleKey, 7);
                $role = $scope === Permission::PLATFORM ? PlatformRole::query()->find($id) : Role::query()->find($id);
                $list = $role === null ? [] : $role->permissions;
            } else {
                continue;
            }

            $granted = array_merge($granted, $list === ['*'] ? $all : array_intersect($list, $all));
        }

        return array_values(array_unique($granted));
    }
}
