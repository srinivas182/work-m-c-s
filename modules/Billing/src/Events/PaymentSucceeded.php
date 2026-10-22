<?php

declare(strict_types=1);

namespace Wurk\Billing\Events;

final readonly class PaymentSucceeded
{
    public function __construct(public string $paymentId) {}
}
