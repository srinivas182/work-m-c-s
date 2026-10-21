<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Events;

use Wurk\Tenancy\Contracts\CurrentTenant;

final readonly class TenantEntered
{
    public function __construct(public CurrentTenant $tenant) {}
}
