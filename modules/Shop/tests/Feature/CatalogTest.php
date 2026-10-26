<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Shop\Http\Controllers\ProductsController;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\CatalogChanged;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('local');
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    $this->product = function (string $name, string $status, array $variants) {
        return ($this->in)(function () use ($name, $status, $variants) {
            $p = Product::query()->create(['name' => $name, 'slug' => Str::slug($name), 'status' => $status, 'options' => [], 'photos' => ['/photos/'.Str::slug($name).'.jpg']]);
            foreach ($variants as $i => $v) {
                Variant::query()->create(['product_id' => $p->id, 'position' => $i, ...$v]);
            }

            return $p;
        });
    };
});

it('lists published products with their “from” price, “was” price and stock — not drafts or empty products', function () {
    ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 30000, 'stock' => 0], ['price_cents' => 25000, 'compare_at_cents' => 30000, 'stock' => 0], ['price_cents' => 28000, 'stock' => null]]);
    ($this->product)('Beanie', 'published', [['price_cents' => 15000, 'stock' => 0]]);
    ($this->product)('Draft cap', 'draft', [['price_cents' => 10000]]);
    ($this->product)('No prices yet', 'published', []);

    $list = ($this->in)(fn () => app(ProductCatalog::class)->published());
    expect(array_column($list, 'name'))->toBe(['Beanie', 'Kasi T-shirt'])
        ->and($list[1])->toMatchArray(['price_cents' => 25000, 'varies' => true, 'compare_at_cents' => 30000, 'photo' => '/photos/kasi-t-shirt.jpg', 'in_stock' => true]) // one variant isn't tracked
        ->and($list[0])->toMatchArray(['varies' => false, 'in_stock' => false]);
});

it('passes products to the renderer, and re-renders only pages with a Products section when they change', function () {
    $this->artisan('website:install-templates');
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<h1>Shop</h1>']))]);
    ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 25000]]);
    $withGrid = ($this->in)(function () {
        app(SiteBuilder::class)->apply('salon-hair-braids');
        foreach (['home', 'services'] as $slug) {
            app(Pages::class)->publish(Page::query()->where('slug', $slug)->firstOrFail(), null, null);
        }
        // The real path: add a Products section to the home page's draft, then publish it.
        $home = Page::query()->where('slug', 'home')->firstOrFail();
        $doc = json_decode((string) PageVersion::query()->whereKey($home->published_version_id)->value('content'), true);
        $doc['blocks'][] = ['id' => 'shop', 'type' => 'ProductGrid', 'version' => 1, 'props' => []];
        app(Pages::class)->saveDraft($home, $doc, (int) $home->draft_revision, null);

        return (string) app(Pages::class)->publish($home->refresh(), null, null)->id;
    });
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->input, '"products":[{') && str_contains((string) $p->input, 'Kasi T-shirt'));

    Queue::fake();
    event(new CatalogChanged($this->shop->id));
    Queue::assertPushed(RenderPublishedPage::class, 1);
    Queue::assertPushed(RenderPublishedPage::class, fn (RenderPublishedPage $job) => $job->versionId === $withGrid);
});

it('announces catalogue changes when products are saved or deleted', function () {
    Event::fake([CatalogChanged::class]);
    $product = ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 25000]]);
    ($this->in)(fn () => app(ProductsController::class)->destroy($product->id));
    Event::assertDispatched(CatalogChanged::class, fn (CatalogChanged $e) => $e->tenantId === $this->shop->id);
});
