<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** Some or all of a purchase was refunded (`refundedCents` is this refund's amount, not the running total). */
final readonly class PurchaseRefunded
{
    public function __construct(public string $sourceType, public string $sourceId, public int $refundedCents) {}
}
