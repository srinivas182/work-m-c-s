<?php

declare(strict_types=1);

namespace Wurk\Identity\Exceptions;

use RuntimeException;

final class OtpThrottled extends RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct("Please wait {$retryAfterSeconds} seconds before requesting another code.");
    }
}
