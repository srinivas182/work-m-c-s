<?php

declare(strict_types=1);

namespace Wurk\Shop\Receptionist;

use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\OrderItem;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\ShopCatalog;

/**
 * "Same as last time?" (Sprint 21.5): the customer's last paid order, ready to check out again with today's prices and
 * stock. Only on WhatsApp, where the number is verified by WhatsApp itself — knowing someone's number in a website chat
 * isn't enough to see their orders. Collection or delivery is chosen at checkout, as always.
 */
final readonly class ReorderTool implements ReceptionistTool
{
    public function __construct(private ShopCatalog $catalog) {}

    public function name(): string
    {
        return 'reorder';
    }

    public function description(): string
    {
        return 'On WhatsApp: the customer’s last order, with a link to order the same again (today’s prices and stock). Use when they ask for "the same as last time" or to reorder.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function capability(): string
    {
        return 'order';
    }

    public function entitlement(): string
    {
        return 'shop';
    }

    public function run(array $arguments, array $context): array
    {
        $phone = self::digits((string) ($context['customer']['phone'] ?? ''));
        if (($context['customer']['verified'] ?? false) !== true || strlen($phone) < 10) {
            throw new ReceptionistRefused('Reordering works on WhatsApp, where their number is verified. Otherwise use the products tool, or offer a person.');
        }
        if (! $this->catalog->canOrder()) {
            throw new ReceptionistRefused('The shop can’t take orders online right now. Offer to pass it to a person.');
        }
        $last = Order::query()->whereNotNull('paid_at')->latest('created_at')->limit(200)->get()
            ->first(fn (Order $o) => self::digits($o->customer_phone) === $phone);
        if ($last === null) {
            return ['found' => false, 'tell_the_customer' => 'There’s no earlier order under this WhatsApp number. Offer to help them choose (products tool).'];
        }
        $lines = [];
        $gone = [];
        $total = 0;
        foreach (OrderItem::query()->where('order_id', $last->id)->get() as $item) {
            $variant = Variant::query()->whereKey($item->variant_id)->first();
            $product = $variant === null ? null : Product::query()->whereKey($variant->product_id)->where('status', 'published')->first();
            $name = trim($item->product_name.' '.($item->variant_label ?? ''));
            if ($variant === null || $product === null || ($variant->stock !== null && $variant->stock < 1)) {
                $gone[] = $name;

                continue;
            }
            $quantity = $variant->stock !== null ? min($item->quantity, $variant->stock) : $item->quantity;
            $lines[] = ['id' => $variant->id, 'quantity' => $quantity, 'name' => $name];
            $total += $variant->price_cents * $quantity;
        }
        if ($lines === []) {
            return ['found' => true, 'available' => false, 'last_order' => $last->created_at->format('j F Y'), 'not_available' => $gone,
                'tell_the_customer' => 'Nothing from their last order is available now. Offer something else (products tool) or a person.'];
        }
        $url = ProductsTool::url('/cart?add='.implode(',', array_map(fn (array $l) => "{$l['id']}:{$l['quantity']}", $lines)));
        $items = array_map(fn (array $l) => "{$l['quantity']} × {$l['name']}", $lines);

        return ['found' => true, 'last_order' => $last->created_at->format('j F Y'), 'last_time' => $last->fulfilment === 'delivery' ? 'delivered' : 'collected',
            'items' => $items, 'subtotal_today' => ProductsTool::money($total), 'not_available' => $gone,
            'tell_the_customer' => 'Ask "Same as last time?" with these items and today’s total. The link opens their cart; they choose collection or delivery and pay there.',
            'card' => ['type' => 'cart', 'service' => implode(', ', $items), 'price' => ProductsTool::money($total), 'action' => ['label' => 'Check out', 'url' => $url]]];
    }

    /** International digits (a leading 0 becomes 27), as phone numbers are compared across WURK. */
    private static function digits(string $phone): string
    {
        $d = (string) preg_replace('/\D/', '', $phone);

        return str_starts_with($d, '0') ? '27'.substr($d, 1) : $d;
    }
}
