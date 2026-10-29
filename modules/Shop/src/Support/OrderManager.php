<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Shipping\Contracts\Couriers;
use Wurk\Shipping\Contracts\ShippingProblem;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Variant;
use Wurk\Team\Contracts\BranchLedger;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Every change to an order goes through here: the journey (paid → packed → ready / out for delivery →
 * done), cancelling (stock back; card payments refunded) and refunds (full or partial) — each written to
 * the order's timeline.
 */
final readonly class OrderManager
{
    /** Which steps may follow which. */
    private const FROM = [
        'paid' => ['awaiting_payment'],
        'packed' => ['paid'],
        'ready' => ['paid', 'packed'],
        'out_for_delivery' => ['paid', 'packed'],
        'done' => ['paid', 'packed', 'ready', 'out_for_delivery'],
    ];

    /** Before it has left the business, an order can be cancelled. */
    public const CANCELLABLE = ['awaiting_payment', 'paid', 'packed'];

    public function __construct(private TenantContext $context, private BusinessPayments $payments, private OrderPlacer $placer, private AuditLog $audit) {}

    /** Move an order along its journey. */
    public function advance(Order $order, string $to, string $by): void
    {
        if (! in_array($order->status, self::FROM[$to] ?? [], true)) {
            throw new CheckoutProblem('That step doesn’t follow from where this order is.');
        }
        if (($to === 'ready' && $order->fulfilment !== 'collection') || ($to === 'out_for_delivery' && $order->fulfilment !== 'delivery')) {
            throw new CheckoutProblem($to === 'ready' ? 'This order is being delivered, not collected.' : 'This order is being collected, not delivered.');
        }
        if ($to === 'paid' && $order->payment_method !== 'offline') {
            throw new CheckoutProblem('Card payments are marked paid automatically once the payment is confirmed.');
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($order, $to, $by): void {
            $order->update(['status' => $to, ...($to === 'paid' ? ['paid_at' => now(), 'hold_expires_at' => null] : [])]);
            $this->log($order, $to, $by);
        });
        if ($to === 'paid') {
            DB::table('shop_holds')->where('order_id', $order->id)->delete(); // the stock is theirs now
            // Paid by EFT into the account whose details the customer was given (the branch's own, or the business's).
            app(BranchLedger::class)->record($this->payments->accountBranch('eft', $order->branch_id), $order->branch_id, $order->total_cents, 'order', 'shop-'.$order->id, "Order #{$order->number} (EFT)");
        }
        $this->audit->record('shop.order_'.$to, 'order', $order->id, ['by' => $by]);
        event(new OrderStatusChanged($this->context->id(), $order->id, $to));
    }

    /** Cancel before it leaves: the stock goes back, and a card payment is refunded in full. */
    public function cancel(Order $order, string $by, ?string $reason = null): bool
    {
        if (! in_array($order->status, self::CANCELLABLE, true)) {
            throw new CheckoutProblem('This order has already left — refund it instead.');
        }
        $wasPaid = $order->status !== 'awaiting_payment';
        $this->placer->release($order, 'cancelled', $by);
        $this->log($order, 'cancelled', $by, null, $reason);
        $refunded = false;
        if ($wasPaid && $order->payment_method === 'card') {
            $refunded = $this->payments->refund('shop-'.$order->id);
            if ($refunded) {
                $order->update(['refunded_cents' => $order->total_cents]);
                app(BranchLedger::class)->record($this->payments->receivedBy('shop-'.$order->id), $order->branch_id, -$order->total_cents, 'refund', 'shop-'.$order->id, "Order #{$order->number} cancelled");
                $this->log($order, 'refunded', 'Payment provider', $order->total_cents);
            }
        }
        event(new OrderStatusChanged($this->context->id(), $order->id, 'cancelled'));

        return $refunded;
    }

    /**
     * Refund all or part of a paid order. Card orders go through the provider; EFT orders are recorded
     * (the business pays the customer back itself). Stock goes back only if asked.
     */
    public function refund(Order $order, ?int $cents, bool $restock, string $by, ?string $note = null): void
    {
        if (in_array($order->status, ['awaiting_payment', 'expired'], true) || ($order->status === 'cancelled' && $order->refunded_cents >= $order->total_cents)) {
            throw new CheckoutProblem('Nothing has been paid to refund.');
        }
        $left = $order->total_cents - $order->refunded_cents;
        $amount = $cents ?? $left;
        if ($amount < 1 || $amount > $left) {
            throw new CheckoutProblem('Refund up to R'.number_format($left / 100, 2).' (what’s left of this order).');
        }
        if ($order->payment_method === 'card' && ! $this->payments->refund('shop-'.$order->id, $amount)) {
            throw new CheckoutProblem('The payment provider didn’t accept the refund. Refund it in your Yoco or PayFast dashboard, then record it here as an EFT refund.');
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($order, $amount, $restock, $by, $note): void {
            if ($restock) {
                foreach (OrderItem::query()->where('order_id', $order->id)->where('stock_tracked', true)->get() as $item) {
                    $variant = Variant::query()->whereKey($item->variant_id)->lockForUpdate()->first();
                    if ($variant !== null) {
                        app(BranchStock::class)->give($variant, (string) $order->branch_id, $item->quantity); // back to the branch it came from
                    }
                }
            }
            $order->update(['refunded_cents' => $order->refunded_cents + $amount]);
            $received = $order->payment_method === 'card' ? $this->payments->receivedBy('shop-'.$order->id) : $this->payments->accountBranch('eft', $order->branch_id);
            app(BranchLedger::class)->record($received, $order->branch_id, -$amount, 'refund', 'shop-'.$order->id, "Order #{$order->number} refund");
            $this->log($order, 'refunded', $by, $amount, $note ?? ($restock ? 'Stock returned' : null));
        });
        $this->audit->record('shop.order_refunded', 'order', $order->id, ['cents' => $amount, 'by' => $by]);
        event(new OrderStatusChanged($this->context->id(), $order->id, 'refunded'));
    }

    public function log(Order $order, string $event, ?string $by, ?int $cents = null, ?string $note = null): void
    {
        DB::connection($this->context->connectionName())->table('shop_order_events')->insert([
            'id' => (string) Str::ulid(), 'tenant_id' => $this->context->id(), 'order_id' => $order->id, 'event' => $event,
            'amount_cents' => $cents, 'note' => $note, 'by' => $by, 'created_at' => now(),
        ]);
    }

    /**
     * Create the courier shipment for a paid courier or Pargo order (through the fulfilling branch's account,
     * else the business's); saves the tracking and label, and sends the order out for delivery.
     */
    public function ship(Order $order, string $by): Order
    {
        if ($order->shipping_provider === null || ! in_array($order->status, ['paid', 'packed'], true)) {
            throw new CheckoutProblem('Only a paid courier or pickup-point order can be shipped.');
        }
        $kg = max(0.1, (int) OrderItem::query()->where('order_id', $order->id)->sum('quantity') * (float) config('shipping.item_kg', 0.5));
        $details = ['provider' => $order->shipping_provider, 'service' => $order->shipping_service, 'pickup_point' => $order->pickup_point,
            'name' => $order->customer_name, 'phone' => $order->customer_phone, 'email' => $order->customer_email, 'value_cents' => $order->subtotal_cents, 'weight_kg' => $kg];
        if ($order->shipping_provider === 'bobgo') {
            $details['to'] = $order->shipping_to ?? ['street' => (string) $order->delivery_address]; // the structured address given at checkout
        }
        try {
            $sent = app(Couriers::class)->ship('shop-'.$order->id, $order->branch_id, $details);
        } catch (ShippingProblem $e) {
            throw new CheckoutProblem($e->getMessage());
        }
        $order->update(['tracking_reference' => $sent['tracking_reference'], 'tracking_url' => $sent['tracking_url'], 'label_url' => $sent['label_url']]);
        $this->advance($order->refresh(), 'out_for_delivery', $by);

        return $order->refresh();
    }
}
