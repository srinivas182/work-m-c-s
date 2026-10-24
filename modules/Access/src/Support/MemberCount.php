<?php

declare(strict_types=1);

namespace Wurk\Access\Support;

use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamSize;
use Wurk\Access\Models\Member;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Team size, and the default seat policy (no limit) until Plans provides the package's. */
final readonly class MemberCount implements SeatPolicy, TeamSize
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
}
