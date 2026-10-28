<?php

declare(strict_types=1);

namespace Wurk\Access\Support;

use Wurk\Access\AccessManager;
use Wurk\Access\Actions\Team;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Access\Contracts\TeamSize;
use Wurk\Access\Models\Member;
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

    public function emailsWithPermission(string $permission): array
    {
        $access = app(AccessManager::class);
        $ids = Member::query()->where('status', 'active')->pluck('identity_id')
            ->map(fn ($id) => (string) $id)->filter(fn (string $id) => $access->allows($id, $permission))->all();
        $ids = array_values($ids);
        $emails = array_filter(array_map(fn (array $c) => $c['email'], app(PeopleDirectory::class)->contacts($ids)));

        return array_values(array_unique($emails));
    }
}
