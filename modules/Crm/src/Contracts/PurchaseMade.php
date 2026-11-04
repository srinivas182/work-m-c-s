<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * A customer paid for something — an order, a booking or class deposit paid online, a sale recorded in store.
 * Raised by the selling module once the payment is confirmed. `commissionableCents` excludes what shouldn't earn
 * rewards (e.g. an order's delivery fee). The customer is found through the contact link for the same source.
 * `finalAt`: when it can no longer be refunded (the business's return window, counted by the seller).
 */
final readonly class PurchaseMade
{
    public function __construct(public string $sourceType, public string $sourceId, public int $paidCents, public int $commissionableCents, public ?string $branchId, public \DateTimeInterface $finalAt) {}
}
