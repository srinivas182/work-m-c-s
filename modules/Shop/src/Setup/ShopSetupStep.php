<?php

declare(strict_types=1);

namespace Wurk\Shop\Setup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\SetupStep;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Setting up by talking (23.1), the shop: when the owner wants online orders, the products they named go on sale at the
 * prices they gave (stock not tracked until they set it). Products that already exist by name are left alone.
 */
final readonly class ShopSetupStep implements SetupStep
{
    public function __construct(private TenantContext $context, private Entitlements $entitlements) {}

    public function order(): int
    {
        return 30;
    }

    public function preview(array $brief): array
    {
        if (! $brief['shop'] || $brief['products'] === []) {
            return [];
        }
        if (! $this->entitlements->entitled($this->context->id(), 'shop')) {
            return ['Online orders aren’t in your package'];
        }
        $new = $this->newProducts($brief);

        return $new === [] ? [] : ['Put '.count($new).' '.(count($new) === 1 ? 'product' : 'products').' on sale in your online shop'];
    }

    public function apply(array $brief, string $by): array
    {
        if (! $brief['shop'] || $brief['products'] === [] || ! $this->entitlements->entitled($this->context->id(), 'shop')) {
            return [];
        }
        $new = $this->newProducts($brief);
        DB::connection($this->context->connectionName())->transaction(function () use ($new): void {
            foreach ($new as $p) {
                $base = Str::slug($p['name']) ?: 'product';
                $slug = $base;
                for ($n = 2; Product::query()->where('slug', $slug)->exists(); $n++) {
                    $slug = $base.'-'.$n;
                }
                $product = Product::query()->create(['name' => $p['name'], 'slug' => $slug, 'status' => 'published', 'options' => []]);
                Variant::query()->create(['product_id' => $product->id, 'price_cents' => $p['price_cents'], 'stock' => null]);
            }
        });

        return $new === [] ? [] : [count($new).' '.(count($new) === 1 ? 'product is' : 'products are').' on sale'];
    }

    /**
     * @param  array<string, mixed>  $brief
     * @return list<array{name: string, price_cents: int}>
     */
    private function newProducts(array $brief): array
    {
        $existing = array_map('mb_strtolower', Product::query()->pluck('name')->all());

        return array_values(array_filter((array) $brief['products'], fn ($p) => $p['price_cents'] > 0 && ! in_array(mb_strtolower($p['name']), $existing, true)));
    }
}
