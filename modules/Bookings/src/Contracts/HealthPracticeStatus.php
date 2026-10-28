<?php

declare(strict_types=1);

namespace Wurk\Bookings\Contracts;

/** Whether the current business is a health practice (Booking settings → Health practice). */
interface HealthPracticeStatus
{
    public function on(): bool;
}
