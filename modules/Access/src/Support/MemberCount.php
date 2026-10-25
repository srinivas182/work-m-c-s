<?php

declare(strict_types=1);

namespace Wurk\Access\Support;

use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Access\Contracts\TeamSize;
use Wurk\Access\Models\Member;
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
}
