<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Whether a business shows "Verified by WURK" (Sprint 25.4): its owner's phone is confirmed and WURK HQ has checked
 * their identity (within the last two years); "Registered business" when its company number matched the registry.
 * Never for a suspended business.
 */
interface BusinessBadge
{
    /** @return array{verified: bool, registered: bool, checked_on: string|null, url: string} */
    public function for(string $tenantId): array;
}
