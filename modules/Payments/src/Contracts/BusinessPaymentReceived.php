<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * A provider says a payment succeeded (signature checked). Listeners must still call
 * BusinessPayments::verify() before acting — a notification alone confirms nothing.
 */
final readonly class BusinessPaymentReceived
{
    public function __construct(public string $tenantId, public string $reference) {}
}
