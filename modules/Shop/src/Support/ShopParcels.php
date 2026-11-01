<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Wurk\Shipping\Contracts\ParcelPlanner;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;

/**
 * The parcel for some variants: each item's weight and size (the variant's own, else its product's), planned into
 * one parcel by Shipping. The ONE calculation for checkout rates and for booking the shipment.
 */
final readonly class ShopParcels
{
    public function __construct(private ParcelPlanner $planner) {}

    /**
     * @param  array<string, int>  $quantities  variant id => quantity
     * @return array{weight_kg: float, size_cm: array{length: float, width: float, height: float}|null, box: string|null, fits: bool}
     */
    public function plan(array $quantities): array
    {
        $variants = Variant::query()->whereIn('id', array_keys($quantities))->get();
        $products = Product::query()->whereIn('id', $variants->pluck('product_id')->unique())->get()->keyBy('id');
        $items = [];
        foreach ($variants as $v) {
            $p = $products[$v->product_id] ?? null;
            $size = fn ($m) => $m !== null && $m->length_mm && $m->width_mm && $m->height_mm ? [(int) $m->length_mm, (int) $m->width_mm, (int) $m->height_mm] : null;
            $items[] = ['weight_g' => $v->weight_g ?? $p?->weight_g, 'size_mm' => $size($v) ?? $size($p), 'quantity' => (int) $quantities[$v->id]];
        }

        return $this->planner->plan($items);
    }
}
