<?php

declare(strict_types=1);

namespace Wurk\Identity\Events;

/** A person was given WURK staff access with a platform role. */
final readonly class StaffGranted
{
    public function __construct(public string $identityId, public string $role) {}
}
