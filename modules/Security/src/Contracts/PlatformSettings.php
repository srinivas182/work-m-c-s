<?php

declare(strict_types=1);

namespace Wurk\Security\Contracts;

/** Read-only platform settings for other modules (e.g. cash-payment approvers). */
interface PlatformSettings
{
    public function get(string $key, mixed $default = null): mixed;
}
