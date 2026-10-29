<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Shop\Mail\NewOrderMail;
use Wurk\Shop\Mail\OrderUpdateMail;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** New-order alerts to the shop's managers, and order updates to the customer (email). */
final readonly class OrderMessages
{
    public function __construct(private TenantContext $context, private TeamDirectory $team) {}

    /** A new order: everyone who can manage the shop is told. */
    public function newOrder(Order $order): void
    {
        $rand = fn (int $c) => 'R'.number_format($c / 100, 2);
        $lines = array_values(OrderItem::query()->where('order_id', $order->id)->get()
            ->map(fn (OrderItem $i) => "{$i->quantity} × {$i->product_name}".($i->variant_label ? " ({$i->variant_label})" : ''))->all());
        $how = ($order->payment_method === 'card' ? 'Paying by card' : 'Paying by EFT or on collection — mark it paid when the money arrives')
            .' · '.($order->fulfilment === 'delivery' ? 'Delivery' : 'Collection');
        $link = 'https://'.config('wurk.domains.app').'/'.$this->context->current()->handle().'/shop/orders/'.$order->id;
        // Branch managers are alerted only about their branches' orders.
        foreach ($this->team->emailsWithPermission('shop.orders', $order->branch_id) as $email) {
            Mail::to($email)->send(new NewOrderMail($order->number, $order->customer_name, $lines, $rand($order->total_cents), $how, $link));
        }
    }

    /** The order moved on: the customer is told (if they gave an email). */
    public function handle(OrderStatusChanged $event): void
    {
        if (! isset(OrderUpdateMail::WORDING[$event->status])) {
            return; // e.g. "packed" is internal
        }
        $tenant = app(TenantDirectory::class)->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($event, $tenant): void {
            $order = Order::query()->whereKey($event->orderId)->first();
            if ($order === null || $order->customer_email === null) {
                return;
            }
            $amount = $event->status === 'refunded'
                ? 'R'.number_format((int) DB::connection($this->context->connectionName())->table('shop_order_events')->where('order_id', $order->id)->where('event', 'refunded')->orderByDesc('id')->value('amount_cents') / 100, 2)
                : '';
            $link = URL::signedRoute('shop.public.order', ['handle' => $tenant->handle(), 'order' => $order->id]);
            Mail::to($order->customer_email)->send(new OrderUpdateMail($tenant->displayName(), $event->status, $order->number, $link, $amount));
        });
    }
}
