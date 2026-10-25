<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use RuntimeException;

/** The time was taken a moment ago (or isn't open): offer the customer other times. */
final class SlotTaken extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sorry — that time has just been taken. Please choose another.');
    }
}
