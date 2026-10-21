<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

/** A business was activated, suspended or deleted. */
final readonly class TenantStatusChanged
{
    public function __construct(public string $tenantId, public string $handle, public string $from, public string $to, public ?string $reason = null) {}
}
