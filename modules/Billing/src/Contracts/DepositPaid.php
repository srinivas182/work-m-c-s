<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

/**
 * Paystack reported a successful deposit payment (reference starting "dep_"). Listeners must
 * still verify it (SplitPayments::verify) before acting — a notification alone confirms nothing.
 */
final readonly class DepositPaid
{
    public function __construct(public string $reference) {}
}
