<?php

declare(strict_types=1);

namespace Wurk\Shop\Receptionist;

use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Money\Money;
use Wurk\Shop\Support\ShopCatalog;
use Wurk\Tenancy\Contracts\TenantContext;

/** Search the shop's published products: names, options, prices and stock — the same catalogue as the website. */
final readonly class ProductsTool implements ReceptionistTool
{
    public function __construct(private ShopCatalog $catalog) {}

    public function name(): string
    {
        return 'products';
    }

    public function description(): string
    {
        return 'Search the shop: products with their options (variant_id), prices and whether they’re in stock. Use before naming a product or price.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['search' => ['type' => 'string', 'description' => 'Words from the product or category, e.g. "hair oil"; empty for everything']]];
    }

    public function capability(): string
    {
        return 'answer';
    }

    public function entitlement(): string
    {
        return 'shop';
    }

    public function run(array $arguments, array $context): array
    {
        $words = array_filter(preg_split('/\s+/', mb_strtolower(trim((string) ($arguments['search'] ?? '')))) ?: []);
        $found = array_filter($this->catalog->pages(), function (array $p) use ($words): bool {
            $text = mb_strtolower($p['name'].' '.strip_tags((string) $p['description']));

            foreach ($words as $w) {
                if (! str_contains($text, $w)) {
                    return false;
                }
            }

            return true;
        });

        return ['can_order_online' => $this->catalog->canOrder(), 'products' => array_values(array_map(fn (array $p) => [
            'name' => $p['name'], 'url' => self::url('/shop/'.$p['slug']),
            'options' => array_map(fn (array $v) => ['variant_id' => $v['id'], 'option' => $v['label'], 'price' => self::money($v['price_cents']), 'in_stock' => $v['in_stock']], $p['variants']),
        ], array_slice($found, 0, 10)))];
    }

    public static function money(int $cents): string
    {
        return Money::format((int) $cents);
    }

    public static function url(string $path): string
    {
        $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';

        return "{$scheme}://".app(TenantContext::class)->current()->handle().'.'.config('wurk.domains.root').$path;
    }
}
