<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

/**
 * Whether one more team member may be added. Access allows by default; Plans replaces this
 * with the package's limit (so Access never depends on Plans).
 */
interface SeatPolicy
{
    /** A plain refusal message, or null when another member may be added. */
    public function refusal(string $tenantId, int $currentMembers): ?string;
}
