<?php

declare(strict_types=1);

namespace Wurk\Identity\Events;

/** Security event; recorded by the Audit module (Sprint 1.4). */
final readonly class SignInFailed
{
    public function __construct(public string $identifierHash, public string $surface, public string $reason) {}
}
