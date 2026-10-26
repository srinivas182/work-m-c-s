<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\Variant;
use Wurk\Website\Contracts\ProductCatalog;

/** The Shop's published products, for the website's shop sections. */
final class ShopCatalog implements ProductCatalog
{
    public function published(): array
    {
        $products = Product::query()->where('status', 'published')->orderBy('position')->orderBy('name')->get();
        $variants = Variant::query()->whereIn('product_id', $products->pluck('id'))->get()->groupBy('product_id');
        $categories = ShopCategory::query()->pluck('name', 'id');
        $out = [];
        foreach ($products as $p) {
            $vs = $variants[$p->id] ?? collect();
            if ($vs->isEmpty()) {
                continue;
            }
            $cheapest = $vs->sortBy('price_cents')->first();
            $out[] = [
                'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug,
                'category' => $p->category_id !== null ? ($categories[$p->category_id] ?? null) : null,
                'price_cents' => $cheapest->price_cents, 'varies' => $vs->pluck('price_cents')->unique()->count() > 1,
                'compare_at_cents' => $cheapest->compare_at_cents, 'photo' => ($p->photos ?? [])[0] ?? null,
                'in_stock' => $vs->contains(fn (Variant $v) => $v->stock === null || $v->stock > 0),
            ];
        }

        return $out;
    }
}
