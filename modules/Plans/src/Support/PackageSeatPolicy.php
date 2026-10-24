<?php

declare(strict_types=1);

namespace Wurk\Plans\Support;

use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Plans\Contracts\Entitlements;

/** The package's team-member limit (0 = no limit). */
final readonly class PackageSeatPolicy implements SeatPolicy
{
    public function __construct(private Entitlements $entitlements) {}

    public function refusal(string $tenantId, int $currentMembers): ?string
    {
        $limit = (int) ($this->entitlements->limits($tenantId)['staff_limit'] ?? 0);

        return $limit > 0 && $currentMembers >= $limit
            ? "Your package includes {$limit} team member".($limit === 1 ? '' : 's').'. Upgrade to add more.'
            : null;
    }
}
