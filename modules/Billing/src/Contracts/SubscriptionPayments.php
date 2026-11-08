<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

/** A paid WURK subscription payment, for other modules (Sprint 14.3: agent commission) without reading Billing's tables. */
interface SubscriptionPayments
{
    /** @return array{tenant_id: string, excl_cents: int, paid_at: \DateTimeInterface}|null  null unless a succeeded subscription payment */
    public function paidSubscription(string $paymentId): ?array;
}
