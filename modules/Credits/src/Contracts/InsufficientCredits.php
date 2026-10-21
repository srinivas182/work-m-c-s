<?php

declare(strict_types=1);

namespace Wurk\Credits\Contracts;

use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(public readonly int $neededMilli, public readonly int $availableMilli)
    {
        parent::__construct('Not enough WURK Credits: this needs '.number_format($neededMilli / 1000, 1).', the balance is '.number_format($availableMilli / 1000, 1).'.');
    }
}
