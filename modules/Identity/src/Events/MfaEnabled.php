<?php

declare(strict_types=1);

namespace Wurk\Identity\Events;

use Wurk\Identity\Models\Identity;

/** Security event; recorded by the Audit module (Sprint 1.4). */
final readonly class MfaEnabled
{
    public function __construct(public Identity $identity) {}
}
