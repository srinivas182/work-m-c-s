<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

interface TeamSize
{
    /** Team members of a business. */
    public function count(string $tenantId): int;
}
