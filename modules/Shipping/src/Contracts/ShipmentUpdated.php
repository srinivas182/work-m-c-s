<?php

declare(strict_types=1);

namespace Wurk\Shipping\Contracts;

/** A courier reported a shipment's progress (e.g. delivered). Raised inside the business. */
final readonly class ShipmentUpdated
{
    public function __construct(public string $tenantId, public string $reference, public string $status) {}
}
