<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Illuminate\Support\Facades\Storage;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Models\ProductRender;

/**
 * Product pages (/shop/{slug}): the site's own header and footer (from the published home page) around
 * the product's details, rendered ahead of time like every page. Pages of products no longer published
 * are removed.
 */
final readonly class ProductPages
{
    /** The cart page is stored alongside product pages under this fixed id. */
    public const CART = '0000000000000000000000CART'; // exactly 26 characters, like every id in product_renders

    public function __construct(private TenantContext $context, private PageRenderer $renderer, private ProductCatalog $catalog) {}

    /** @return int product pages rendered */
    public function renderAll(): int
    {
        $products = $this->catalog->pages();
        $disk = Storage::disk((string) config('wurk.site_pages_disk', 'local'));
        $canOrder = $this->catalog->canOrder();
        $keep = [...array_column($products, 'id'), ...($canOrder ? [self::CART] : [])];
        $stale = ProductRender::query()->whereNotIn('product_id', $keep)->get();
        foreach ($stale as $old) {
            $disk->delete($old->path);
            $old->delete();
        }
        $home = Page::query()->where('is_home', true)->whereNotNull('published_version_id')->first();
        if ($home === null) {
            return 0; // nothing published yet: product pages wait for the site
        }
        $document = (array) json_decode((string) PageVersion::query()->whereKey($home->published_version_id)->value('content'), true);
        $frame = array_values(array_filter((array) ($document['blocks'] ?? []), fn ($b) => in_array($b['type'] ?? null, ['Header', 'Footer'], true)));
        $header = array_values(array_filter($frame, fn ($b) => $b['type'] === 'Header'))[0] ?? null;
        $footer = array_values(array_filter($frame, fn ($b) => $b['type'] === 'Footer'))[0] ?? null;
        $business = $this->context->current()->displayName();

        foreach ($products as $product) {
            $out = $this->renderer->renderDocument([
                ...$document,
                'blocks' => array_values(array_filter([$header, ['id' => 'product', 'type' => 'ProductDetail', 'version' => 1, 'props' => []], $footer])),
            ], ['product' => [...$product, 'can_order' => $canOrder]]);
            $path = 'rendered/'.$this->context->id().'/products/'.$product['id'].'.json';
            $disk->put($path, (string) json_encode([
                'body' => $out['html'], 'fonts' => $out['fonts'], 'blocks' => $out['blocks'],
                'title' => $product['name'].' · '.$business,
                'description' => $product['description'] !== null ? mb_substr(trim(strip_tags($product['description'])), 0, 155) : null,
                'image' => $product['photos'][0] ?? null,
            ]));
            ProductRender::query()->updateOrCreate(['product_id' => $product['id']], ['slug' => $product['slug'], 'path' => $path, 'rendered_at' => now()]);
        }

        if ($canOrder) {
            $out = $this->renderer->renderDocument([...$document, 'blocks' => array_values(array_filter([$header, ['id' => 'checkout', 'type' => 'Checkout', 'version' => 1, 'props' => []], $footer]))]);
            $path = 'rendered/'.$this->context->id().'/products/cart.json';
            $disk->put($path, (string) json_encode(['body' => $out['html'], 'fonts' => $out['fonts'], 'blocks' => $out['blocks'], 'title' => 'Your cart · '.$business, 'description' => null, 'image' => null]));
            ProductRender::query()->updateOrCreate(['product_id' => self::CART], ['slug' => 'cart', 'path' => $path, 'rendered_at' => now()]);
        }

        return count($products);
    }
}
