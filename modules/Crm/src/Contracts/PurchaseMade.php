<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * A customer paid for something — an order, a booking or class deposit paid online, a sale recorded in store.
 * Raised by the selling module once the payment is confirmed. `commissionableCents` excludes what shouldn't earn
 * rewards (e.g. an order's delivery fee). The customer is found through the contact link for the same source.
 */
final readonly class PurchaseMade
{
    public function __construct(public string $sourceType, public string $sourceId, public int $paidCents, public int $commissionableCents, public ?string $branchId) {}
}
