<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Places orders without overselling: in one transaction the shop's settings row (order numbers) and each
 * variant are locked, availability checked, stock taken off and the order saved — or nothing at all.
 * Prices always come from the server; names and prices are copied into the order.
 */
final readonly class OrderPlacer
{
    public const MAX_QUANTITY = 50;

    /** Card payments: the stock is held while the customer pays. */
    public const CARD_HOLD_MINUTES = 15;

    public function __construct(private TenantContext $context, private AuditLog $audit) {}

    /**
     * @param  list<array{variant: string, quantity: int}>  $lines
     * @param  array{name: string, phone: string, email?: string|null, address?: string|null, notes?: string|null}  $customer
     */
    public function place(array $lines, array $customer, string $fulfilment, string $method): Order
    {
        $settings = ShopSettings::query()->find($this->context->id());
        $card = $method === 'card' && app(BusinessPayments::class)->available() !== [];
        $eft = $method === 'offline' && app(BusinessPayments::class)->eftInstructions() !== null; // EFT / bank transfer, from Settings → Payments
        if (! $card && ! $eft) {
            throw new CheckoutProblem('Ordering online isn’t available yet. Please contact us to order.');
        }
        $settings ??= new ShopSettings(['collection_enabled' => true, 'delivery_enabled' => false]); // a card-only shop with nothing saved yet
        if (($fulfilment === 'collection' && ! $settings->collection_enabled) || ($fulfilment === 'delivery' && ! $settings->delivery_enabled) || ! in_array($fulfilment, ['collection', 'delivery'], true)) {
            throw new CheckoutProblem('Please choose collection or delivery.');
        }
        if ($fulfilment === 'delivery' && trim((string) ($customer['address'] ?? '')) === '') {
            throw new CheckoutProblem('Please add the delivery address.');
        }
        $wanted = [];
        foreach ($lines as $line) {
            $wanted[$line['variant']] = min(self::MAX_QUANTITY, ($wanted[$line['variant']] ?? 0) + max(1, (int) $line['quantity']));
        }
        if ($wanted === []) {
            throw new CheckoutProblem('Your cart is empty.');
        }

        $order = DB::connection($this->context->connectionName())->transaction(function () use ($wanted, $customer, $fulfilment, $method): Order {
            $settings = ShopSettings::query()->lockForUpdate()->find($this->context->id())
                ?? ShopSettings::query()->create(['tenant_id' => $this->context->id()]); // card-only shops may not have saved settings
            $items = [];
            $subtotal = 0;
            foreach (Variant::query()->whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get() as $variant) {
                $product = Product::query()->whereKey($variant->product_id)->first();
                $name = $product === null ? 'This item' : $product->name;
                if ($product === null || $product->status !== 'published') {
                    throw new CheckoutProblem("{$name} isn’t available any more. Please remove it from your cart.");
                }
                $quantity = $wanted[$variant->id];
                if ($variant->stock !== null && $variant->stock < $quantity) {
                    $what = $variant->label ? "{$name} ({$variant->label})" : $name;
                    throw new CheckoutProblem($variant->stock === 0 ? "{$what} has just sold out." : "Only {$variant->stock} of {$what} left — please change the quantity.");
                }
                if ($variant->stock !== null) {
                    $variant->update(['stock' => $variant->stock - $quantity]);
                }
                $items[] = ['variant_id' => $variant->id, 'product_name' => $product->name, 'variant_label' => $variant->label, 'unit_cents' => $variant->price_cents,
                    'quantity' => $quantity, 'stock_tracked' => $variant->stock !== null, 'line_cents' => $variant->price_cents * $quantity];
                $subtotal += $variant->price_cents * $quantity;
            }
            if (count($items) !== count($wanted)) {
                throw new CheckoutProblem('Something in your cart isn’t available any more. Please check your cart.');
            }
            $delivery = $fulfilment === 'delivery' ? $settings->delivery_fee_cents : 0;
            $order = Order::query()->create([
                'number' => $settings->next_order_number, 'status' => 'awaiting_payment', 'payment_method' => $method, 'fulfilment' => $fulfilment,
                'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'], 'customer_email' => $customer['email'] ?? null,
                'delivery_address' => $fulfilment === 'delivery' ? ($customer['address'] ?? null) : null, 'notes' => $customer['notes'] ?? null,
                'subtotal_cents' => $subtotal, 'delivery_cents' => $delivery, 'total_cents' => $subtotal + $delivery,
                'hold_expires_at' => $method === 'card' ? now()->addMinutes(self::CARD_HOLD_MINUTES) : now()->addHours($settings->offline_hold_hours),
            ]);
            foreach ($items as $item) {
                OrderItem::query()->create(['order_id' => $order->id, ...$item]);
            }
            $settings->update(['next_order_number' => $settings->next_order_number + 1]);

            return $order;
        });
        DB::table('shop_holds')->insert(['order_id' => $order->id, 'tenant_id' => $this->context->id(), 'expires_at' => $order->hold_expires_at]);
        $this->audit->record('shop.order_placed', 'order', $order->id, ['number' => $order->number, 'total_cents' => $order->total_cents]);
        app(OrderManager::class)->log($order, 'placed', 'Customer');

        return $order;
    }

    /** Cancelled or expired: the stock goes back. */
    public function release(Order $order, string $status, ?string $by): void
    {
        if (! in_array($order->status, Order::HOLDING, true) && $status === 'expired') {
            return;
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($order, $status): void {
            foreach (OrderItem::query()->where('order_id', $order->id)->where('stock_tracked', true)->get() as $item) {
                $variant = Variant::query()->whereKey($item->variant_id)->lockForUpdate()->first();
                $variant?->update(['stock' => (int) $variant->stock + $item->quantity]);
            }
            $order->update(['status' => $status, 'hold_expires_at' => null]);
        });
        DB::table('shop_holds')->where('order_id', $order->id)->delete();
        $this->audit->record('shop.order_'.$status, 'order', $order->id, ['by' => $by]);
        if ($status === 'expired') {
            app(OrderManager::class)->log($order, 'expired', null, null, 'Payment didn’t arrive in time; stock returned');
        }
    }
}
