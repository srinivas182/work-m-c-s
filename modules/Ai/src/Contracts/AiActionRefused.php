<?php

declare(strict_types=1);

namespace Wurk\Ai\Contracts;

use RuntimeException;

/** reason: not_included | insufficient */
final class AiActionRefused extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
