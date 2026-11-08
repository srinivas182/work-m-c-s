<?php

declare(strict_types=1);

namespace Wurk\Billing\Events;

/** A card payment disputed with the bank and taken back (recorded by WURK staff; Sprint 14.4). */
final readonly class PaymentChargedBack
{
    public function __construct(public string $paymentId) {}
}
