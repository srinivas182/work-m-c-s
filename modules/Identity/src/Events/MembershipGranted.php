<?php

declare(strict_types=1);

namespace Wurk\Identity\Events;

/**
 * A person joined a business. The Access module creates the business-side team
 * record and gives them the requested role.
 */
final readonly class MembershipGranted
{
    public function __construct(
        public string $identityId,
        public string $tenantId,
        public string $kind,
        public string $displayName,
        public ?string $role = null,
    ) {}
}
