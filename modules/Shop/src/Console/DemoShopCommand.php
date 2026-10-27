<?php

declare(strict_types=1);

namespace Wurk\Shop\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Website\Contracts\CatalogChanged;

/**
 * Gives the retail demo sites (website:demo-sites) a shop with sample products — one sold out — so browser
 * tests, accessibility scans and the performance gate cover real product grids and pages.
 * Development and tests only; safe to run again.
 */
final class DemoShopCommand extends Command
{
    protected $signature = 'shop:demo';

    protected $description = 'Give the retail demo sites sample products (development and tests only)';

    private const PRODUCTS = [
        'retail-boutique' => [
            ['Linen summer dress', 'look-1', [['S', 89900, 4], ['M', 89900, 6], ['L', 89900, 0]], 109900],
            ['Woven tote bag', 'look-2', [[null, 45000, 12]], null],
            ['Silk scarf', 'look-3', [[null, 32000, 0]], null],
            ['Gift card', 'store', [[null, 50000, null]], null],
        ],
        'retail-general-store' => [
            ['Rooibos tea, 80 bags', 'shelves', [[null, 4500, 40]], null],
            ['Enamel mug', 'counter', [['White', 9900, 10], ['Blue', 9900, 0], ['Green', 9900, 3]], null],
            ['Firelighters', 'shelves', [[null, 3200, 0]], null],
            ['Braai salt', 'counter', [[null, 5500, null]], 6500],
        ],
    ];

    public function handle(TenantDirectory $tenants, TenantContext $context, Catalog $catalog, Entitlements $entitlements): int
    {
        if (app()->environment('production')) {
            $this->error('Demo shops are for development and tests only.');

            return self::FAILURE;
        }
        $growth = collect($catalog->onSale())->firstWhere('key', 'growth');
        if ($growth === null) {
            $this->error('No Growth package: run packages:seed-placeholders first.');

            return self::FAILURE;
        }
        foreach (self::PRODUCTS as $template => $products) {
            $tenant = $tenants->findByHandle('demo-'.$template);
            if ($tenant === null) {
                continue;
            }
            $catalog->subscriptionOf($tenant->tenantId()) === null
                ? $catalog->subscribe($tenant->tenantId(), (string) $growth['id'], 'monthly', allowTrial: true)
                : $catalog->changePackage($tenant->tenantId(), (string) $growth['id'], 'monthly');
            $entitlements->flush();
            $made = $context->run($tenant, function () use ($template, $products): bool {
                if (Product::query()->exists()) {
                    return false;
                }
                foreach ($products as $i => [$name, $photo, $variants, $was]) {
                    $sized = $variants[0][0] !== null;
                    $product = Product::query()->create([
                        'name' => $name, 'slug' => Str::slug($name), 'status' => 'published', 'position' => $i,
                        'description' => "A demo product.\n\nShown so the shop can be tested end to end.",
                        'options' => $sized ? [$template === 'retail-boutique' ? 'Size' : 'Colour'] : [],
                        'photos' => ["/images/templates/{$template}/{$photo}-1600.webp"],
                    ]);
                    foreach ($variants as $j => [$value, $price, $stock]) {
                        $option = $template === 'retail-boutique' ? 'Size' : 'Colour';
                        Variant::query()->create(['product_id' => $product->id, 'position' => $j, 'price_cents' => $price, 'stock' => $stock,
                            'compare_at_cents' => $was, 'values' => $value === null ? null : [$option => $value], 'label' => $value]);
                    }
                }

                return true;
            });
            if ($made) {
                event(new CatalogChanged($tenant->tenantId())); // grids and product pages re-render
                $this->line("{$tenant->handle()}: shop ready");
            }
        }

        return self::SUCCESS;
    }
}
