<?php

declare(strict_types=1);

namespace Wurk\Bookings\Events;

final readonly class BookingConfirmed
{
    public function __construct(public string $tenantId, public string $bookingId) {}
}
