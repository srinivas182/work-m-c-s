<?php

declare(strict_types=1);

namespace Wurk\Access\Support;

use Wurk\Access\AccessManager;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Access\Contracts\TeamSize;
use Wurk\Access\Events\AccessChanged;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\Member;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Team size, and the default seat policy (no limit) until Plans provides the package's. */
final readonly class MemberCount implements SeatPolicy, TeamDirectory, TeamSize
{
    public function __construct(private TenantContext $context, private TenantDirectory $tenants) {}

    public function count(string $tenantId): int
    {
        $tenant = $this->tenants->find($tenantId);

        return $tenant === null ? 0 : (int) $this->context->run($tenant, fn () => Member::query()->count());
    }

    public function refusal(string $tenantId, int $currentMembers): ?string
    {
        return null;
    }

    public function members(): array
    {
        $members = [];
        foreach (Member::query()->orderBy('display_name')->get(['id', 'display_name']) as $m) {
            $members[] = ['id' => (string) $m->id, 'name' => (string) $m->display_name];
        }

        return $members;
    }

    public function add(string $identityId, string $displayName, string $role): string
    {
        return (string) app(Team::class)->add($identityId, $displayName, $role)->id;
    }

    public function emailsWithPermission(string $permission, ?string $branchId = null): array
    {
        $access = app(AccessManager::class);
        $ids = Member::query()->where('status', 'active')->pluck('identity_id')
            ->map(fn ($id) => (string) $id)->filter(fn (string $id) => $access->allows($id, $permission)
                && ($branchId === null || ($limit = $access->branchRestriction($id)) === null || in_array($branchId, $limit, true)))->all();
        $ids = array_values($ids);
        $emails = array_filter(array_map(fn (array $c) => $c['email'], app(PeopleDirectory::class)->contacts($ids)));

        return array_values(array_unique($emails));
    }

    public function roster(): array
    {
        $out = [];
        foreach (Member::query()->with('branches')->orderBy('display_name')->get() as $m) {
            $roles = $m->roleKeys();
            $out[] = ['id' => (string) $m->id, 'identity_id' => (string) $m->identity_id, 'name' => (string) $m->display_name,
                'role' => in_array(SystemRoles::OWNER, $roles, true) ? SystemRoles::OWNER : ($roles[0] ?? SystemRoles::STAFF),
                'all_branches' => (bool) $m->all_branches, 'branches' => array_values(array_map('strval', $m->branches->pluck('branch_id')->all())),
                'active' => $m->status === 'active'];
        }

        return $out;
    }

    public function roles(): array
    {
        $out = [];
        foreach (SystemRoles::DEFINITIONS as $key => $def) {
            if ($def['scope'] === Permission::BUSINESS) {
                $out[] = ['key' => $key, 'name' => $def['name'], 'branch_restricted' => (bool) ($def['branch_restricted'] ?? false)];
            }
        }

        return $out;
    }

    public function setRole(string $memberId, string $role): void
    {
        $member = Member::query()->whereKey($memberId)->firstOrFail();
        $team = app(Team::class);
        $team->assign($member, $role);
        foreach (array_diff($member->refresh()->roleKeys(), [$role]) as $other) {
            $team->revoke($member, $other); // refuses to leave the business without an owner
        }
    }

    public function setBranches(string $memberId, ?array $branchIds): void
    {
        app(Team::class)->setBranches(Member::query()->whereKey($memberId)->firstOrFail(), $branchIds);
    }

    public function deactivate(string $memberId): void
    {
        $member = Member::query()->whereKey($memberId)->firstOrFail();
        if (in_array(SystemRoles::OWNER, $member->roleKeys(), true)
            && Member::query()->where('status', 'active')->whereKeyNot($member->id)->get()->filter(fn (Member $m) => in_array(SystemRoles::OWNER, $m->roleKeys(), true))->isEmpty()) {
            throw new AccessRuleViolation('A business must always have at least one owner.');
        }
        $member->update(['status' => 'inactive']);
        event(new AccessChanged('member.deactivated', 'member', (string) $member->id, ['identity_id' => (string) $member->identity_id]));
    }
}
