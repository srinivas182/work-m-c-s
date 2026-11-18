<?php

declare(strict_types=1);

namespace Wurk\Website\Contracts;

/** Whether a business's website has gone live (its home page first published), for other modules (23.4: agents). */
interface SiteLaunches
{
    public function launched(string $tenantId): bool;
}
