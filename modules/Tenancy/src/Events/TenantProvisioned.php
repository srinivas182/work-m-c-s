<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

final readonly class TenantProvisioned
{
    public function __construct(public string $tenantId, public string $handle, public string $name, public string $shardId) {}
}
