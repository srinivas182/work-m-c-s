<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use RuntimeException;

final class TableFull extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sorry — we’re full at that time. Please choose another time.');
    }
}
