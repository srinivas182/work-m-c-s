<?php

declare(strict_types=1);

namespace Wurk\Access\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Access\AccessManager;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Events\AccessChanged;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\BranchMember;
use Wurk\Access\Models\Member;
use Wurk\Access\Models\MemberRole;
use Wurk\Access\Models\Role;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Team membership, roles and branch assignments inside the current business.
 * Every method runs in the current tenant context (enforced by BelongsToTenant).
 */
final readonly class Team
{
    public function __construct(private AccessManager $access, private SeatPolicy $seats, private TenantContext $context) {}

    public function add(string $identityId, string $displayName, string $roleKey = SystemRoles::STAFF): Member
    {
        $this->assertBusinessRole($roleKey);

        if (! Member::query()->where('identity_id', $identityId)->exists()
            && ($refusal = $this->seats->refusal($this->context->id(), Member::query()->count())) !== null) {
            throw ValidationException::withMessages(['member' => $refusal]);
        }

        $member = Member::query()->firstOrCreate(['identity_id' => $identityId], [
            'display_name' => $displayName,
            'status' => 'active',
            'all_branches' => true,
        ]);
        $this->assign($member, $roleKey);

        return $member;
    }

    public function assign(Member $member, string $roleKey): void
    {
        $this->assertBusinessRole($roleKey);
        $role = MemberRole::query()->firstOrCreate(['member_id' => $member->id, 'role_key' => $roleKey]);
        if ($role->wasRecentlyCreated) {
            event(new AccessChanged('role.assigned', 'member', $member->id, ['identity_id' => $member->identity_id, 'role' => $roleKey]));
        }

        if (SystemRoles::DEFINITIONS[$roleKey]['branch_restricted'] ?? false) {
            $member->forceFill(['all_branches' => false])->save();
        }

        $this->access->flush();
    }

    public function revoke(Member $member, string $roleKey): void
    {
        DB::connection($member->getConnectionName())->transaction(function () use ($member, $roleKey): void {
            if ($roleKey === SystemRoles::OWNER) {
                $owners = MemberRole::query()->where('role_key', SystemRoles::OWNER)->lockForUpdate()->count();
                if ($owners <= 1 && MemberRole::query()->where('member_id', $member->id)->where('role_key', SystemRoles::OWNER)->exists()) {
                    throw new AccessRuleViolation('A business must always have at least one owner.');
                }
            }

            MemberRole::query()->where('member_id', $member->id)->where('role_key', $roleKey)->delete();
        });
        event(new AccessChanged('role.revoked', 'member', $member->id, ['identity_id' => $member->identity_id, 'role' => $roleKey]));

        $this->access->flush();
    }

    /**
     * Limit a member to certain branches, or pass null for all branches.
     *
     * @param  list<string>|null  $branchIds
     */
    public function setBranches(Member $member, ?array $branchIds): void
    {
        BranchMember::query()->where('member_id', $member->id)->delete();

        if ($branchIds === null) {
            $member->forceFill(['all_branches' => true])->save();
        } else {
            foreach (array_unique($branchIds) as $branchId) {
                BranchMember::query()->create(['member_id' => $member->id, 'branch_id' => $branchId]);
            }
            $member->forceFill(['all_branches' => false])->save();
        }

        event(new AccessChanged('member.branches_changed', 'member', $member->id, ['branches' => $branchIds ?? 'all']));
        $this->access->flush();
    }

    private function assertBusinessRole(string $roleKey): void
    {
        $valid = SystemRoles::exists($roleKey)
            ? SystemRoles::scope($roleKey) === Permission::BUSINESS
            : str_starts_with($roleKey, 'custom:') && Role::query()->whereKey(substr($roleKey, 7))->exists();

        if (! $valid) {
            throw new AccessRuleViolation("[{$roleKey}] is not a role in this business.");
        }
    }
}
