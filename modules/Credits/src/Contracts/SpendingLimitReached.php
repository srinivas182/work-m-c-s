<?php

declare(strict_types=1);

namespace Wurk\Credits\Contracts;

/** A spending limit (the business's monthly limit, or a staff member's) stops a charge. Handled like a short balance. */
final class SpendingLimitReached extends InsufficientCredits
{
    public function __construct(int $neededMilli, int $leftMilli, public readonly string $whose)
    {
        parent::__construct($neededMilli, $leftMilli);
        $this->message = $whose === 'staff'
            ? 'You’ve reached your monthly spending limit. Ask the business owner to raise it.'
            : 'This business has reached its monthly spending limit. The owner can raise it under Plan & usage.';
    }
}
