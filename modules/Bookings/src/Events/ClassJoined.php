<?php

declare(strict_types=1);

namespace Wurk\Bookings\Events;

final readonly class ClassJoined
{
    public function __construct(public string $tenantId, public string $attendeeId) {}
}
