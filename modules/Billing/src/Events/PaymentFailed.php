<?php

declare(strict_types=1);

namespace Wurk\Billing\Events;

/** A subscription payment failed; dunning (Sprint 3.4) reacts to this. */
final readonly class PaymentFailed
{
    public function __construct(public string $tenantId, public string $paymentId, public string $reason) {}
}
