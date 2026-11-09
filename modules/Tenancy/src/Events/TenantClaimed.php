<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

/** A demo business was claimed by its new owner through signup ("Make it yours", Sprint 14.8b) — raised once they're its owner. */
final readonly class TenantClaimed
{
    public function __construct(public string $tenantId, public string $ownerIdentityId) {}
}
