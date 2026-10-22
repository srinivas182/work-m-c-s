<?php

declare(strict_types=1);

namespace Wurk\Security\Contracts;

/** Platform-wide settings for other modules (cash approvers, invoice issuer details, dunning schedule). */
interface PlatformSettings
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value, ?string $by): void;
}
