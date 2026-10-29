<?php

declare(strict_types=1);

namespace Wurk\Shop\Listeners;

use Wurk\Shipping\Contracts\ShipmentUpdated;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Support\OrderManager;

/** A courier delivered a shop order's parcel: the order is done. */
final class MarkDeliveredOrders
{
    public function handle(ShipmentUpdated $event): void
    {
        if ($event->status !== 'delivered' || ! str_starts_with($event->reference, 'shop-')) {
            return;
        }
        $order = Order::query()->withoutGlobalScope('branch')->find(substr($event->reference, 5));
        if ($order !== null && $order->status === 'out_for_delivery') {
            app(OrderManager::class)->advance($order, 'done', 'Courier');
        }
    }
}
