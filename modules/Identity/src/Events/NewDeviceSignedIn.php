<?php

declare(strict_types=1);

namespace Wurk\Identity\Events;

use Wurk\Identity\Models\Identity;

/** Someone signed in to this WURK ID on a device it hasn't used before (Sprint 18.2b) — the person is emailed. */
final readonly class NewDeviceSignedIn
{
    public function __construct(public Identity $identity, public string $device, public ?string $ip, public string $surface) {}
}
