<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use RuntimeException;

final class ClassFull extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Sorry — this class is full. Please choose another one.');
    }
}
