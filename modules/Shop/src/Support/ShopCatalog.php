<?php

declare(strict_types=1);

namespace Wurk\Shop\Support;

use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;

/** The Shop's published products, for the website's shop sections. */
final class ShopCatalog implements ProductCatalog
{
    public function published(): array
    {
        // Only while the business's package includes the shop: after a downgrade, grids hide and product pages go.
        if (! app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'shop')) {
            return [];
        }
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

    public function pages(): array
    {
        $summaries = [];
        foreach ($this->published() as $p) {
            $summaries[$p['id']] = $p;
        }
        $out = [];
        foreach (Product::query()->whereIn('id', array_keys($summaries))->orderBy('position')->orderBy('name')->get() as $p) {
            $s = $summaries[$p->id];
            $out[] = [
                'id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'description' => $p->description,
                'photos' => array_values($p->photos ?? []), 'options' => array_values($p->options ?? []),
                'price_cents' => $s['price_cents'], 'varies' => $s['varies'], 'compare_at_cents' => $s['compare_at_cents'], 'in_stock' => $s['in_stock'],
                'variants' => array_values(Variant::query()->where('product_id', $p->id)->orderBy('position')->get()->map(fn (Variant $v) => [
                    'id' => $v->id, 'label' => $v->label, 'price_cents' => $v->price_cents, 'compare_at_cents' => $v->compare_at_cents,
                    'in_stock' => $v->stock === null || $v->stock > 0,
                ])->all()),
            ];
        }

        return $out;
    }
}
