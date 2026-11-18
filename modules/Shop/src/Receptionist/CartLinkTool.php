<?php

declare(strict_types=1);

namespace Wurk\Shop\Receptionist;

use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\ShopCatalog;

/**
 * A link that fills the customer's cart on the website, ready to check out (Sprint 19.2c). The customer pays and
 * chooses delivery on the website itself, where the server re-checks every price and stock level: the chat never
 * takes card details.
 */
final readonly class CartLinkTool implements ReceptionistTool
{
    public function __construct(private ShopCatalog $catalog) {}

    public function name(): string
    {
        return 'cart_link';
    }

    public function description(): string
    {
        return 'Make a link that puts the chosen products in the customer’s cart on the website, where they choose delivery and pay. Only after they said which options and how many.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
            'variant_id' => ['type' => 'string', 'description' => 'From the products tool'],
            'quantity' => ['type' => 'integer'],
        ], 'required' => ['variant_id', 'quantity']]]], 'required' => ['items']];
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
        if (! $this->catalog->canOrder()) {
            throw new ReceptionistRefused('The shop can’t take orders online right now. Offer to pass it to a person.');
        }
        $lines = [];
        $total = 0;
        foreach (array_slice((array) ($arguments['items'] ?? []), 0, 20) as $item) {
            $variant = Variant::query()->whereKey((string) ($item['variant_id'] ?? ''))->first();
            $product = $variant === null ? null : Product::query()->whereKey($variant->product_id)->where('status', 'published')->first();
            if ($variant === null || $product === null) {
                throw new ReceptionistRefused('One of those isn’t in the shop. Use the products tool for the variant_id.');
            }
            $quantity = min(max((int) ($item['quantity'] ?? 1), 1), 99);
            if ($variant->stock !== null && $variant->stock < $quantity) {
                throw new ReceptionistRefused("Only {$variant->stock} of {$product->name} in stock.");
            }
            $lines[] = ['id' => $variant->id, 'quantity' => $quantity, 'name' => trim($product->name.' '.($variant->label ?? ''))];
            $total += $variant->price_cents * $quantity;
        }
        if ($lines === []) {
            throw new ReceptionistRefused('Say which products and how many first.');
        }
        $url = ProductsTool::url('/cart?add='.implode(',', array_map(fn (array $l) => "{$l['id']}:{$l['quantity']}", $lines)));

        return ['cart_url' => $url, 'items' => array_map(fn (array $l) => "{$l['quantity']} × {$l['name']}", $lines), 'subtotal' => ProductsTool::money($total),
            'tell_the_customer' => 'The link opens their cart on the website with these items; delivery and payment are chosen there.',
            'card' => ['type' => 'cart', 'service' => implode(', ', array_map(fn (array $l) => "{$l['quantity']} × {$l['name']}", $lines)),
                'price' => ProductsTool::money($total), 'action' => ['label' => 'Check out', 'url' => $url]]];
    }
}
