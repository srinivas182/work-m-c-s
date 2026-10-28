<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/** A provider says a payment to WURK succeeded (verified). Listeners still verify() before acting. */
final readonly class PlatformPaymentReceived
{
    public function __construct(public string $reference) {}
}
