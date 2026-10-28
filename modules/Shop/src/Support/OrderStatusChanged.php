<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

/** An order moved on (paid, ready, out for delivery, cancelled, refunded): the customer is told. */
final readonly class OrderStatusChanged
{
    public function __construct(public string $tenantId, public string $orderId, public string $status) {}
}
