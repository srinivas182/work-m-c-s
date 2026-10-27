<?php

declare(strict_types=1);

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Http\Controllers\ProductsController;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\CatalogChanged;
use Wurk\Website\Contracts\ProductCatalog;
use Wurk\Website\Jobs\RenderPublishedPage;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\PageVersion;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\ProductPages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('local');
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->package = function (array $modules) {
        $p = PackageCatalog::class;
        $pkg = app($p)->create([
            'key' => 'k'.count($modules).random_int(1, 99999), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        $catalog = app(Catalog::class);
        $catalog->subscriptionOf($this->shop->id) === null
            ? $catalog->subscribe($this->shop->id, $pkg->id, 'monthly', allowTrial: true)
            : $catalog->changePackage($this->shop->id, $pkg->id, 'monthly'); // a downgrade, as in real life
        app(Entitlements::class)->flush();
    };
    ($this->package)(['website', 'shop']);
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

it('gives each published product its own page at /shop/{slug} — and removes it when unpublished', function () {
    $this->artisan('website:install-templates');
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<main><h1>Kasi T-shirt</h1></main>']))]);
    $tee = ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 25000]]);
    ($this->product)('Draft cap', 'draft', [['price_cents' => 10000]]);
    ($this->in)(function () {
        app(SiteBuilder::class)->apply('salon-hair-braids');
        app(Pages::class)->publish(Page::query()->where('is_home', true)->firstOrFail(), null, null); // also queues product pages (sync here)
    });
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->input, '"product":{') && str_contains((string) $p->input, '"type":"ProductDetail"'));

    $site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->get("{$site}/shop/kasi-t-shirt")->assertOk()->assertSee('<h1>Kasi T-shirt</h1>', false)
        ->assertHeader('Cache-Tag', 'site-'.$this->shop->id)->assertHeader('X-Frame-Options');
    $this->get("{$site}/shop/draft-cap")->assertNotFound();
    $this->get("{$site}/shop/no-such-thing")->assertNotFound();

    ($this->in)(function () use ($tee) {
        $tee->update(['status' => 'draft']);
        app(ProductPages::class)->renderAll();
    });
    $this->get("{$site}/shop/kasi-t-shirt")->assertNotFound();
});

it('shows nothing once the business’s package no longer includes the shop', function () {
    ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 25000]]);
    expect(($this->in)(fn () => app(ProductCatalog::class)->published()))->toHaveCount(1);
    ($this->package)(['website', 'bookings']); // downgraded
    expect(($this->in)(fn () => app(ProductCatalog::class)->published()))->toBe([])
        ->and(($this->in)(fn () => app(ProductCatalog::class)->pages()))->toBe([]);
});

it('builds the cart page only while the business takes orders online', function () {
    $this->artisan('website:install-templates');
    Process::fake(['*' => Process::result(output: json_encode(['html' => '<main><h1>Your cart</h1></main>']))]);
    ($this->product)('Kasi T-shirt', 'published', [['price_cents' => 25000]]);
    ($this->in)(function () {
        app(SiteBuilder::class)->apply('salon-hair-braids');
        app(Pages::class)->publish(Page::query()->where('is_home', true)->firstOrFail(), null, null);
    });
    $site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->get("{$site}/cart")->assertNotFound(); // ordering not switched on

    ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'offline_enabled' => true, 'offline_instructions' => 'FNB']);
        app(ProductPages::class)->renderAll();
    });
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->input, '"type":"Checkout"'));   // the cart page
    Process::assertRan(fn (PendingProcess $p) => str_contains((string) $p->input, '"can_order":true'));    // product pages show Add to cart
    $this->get("{$site}/cart")->assertOk()->assertSee('<h1>Your cart</h1>', false);

    ($this->in)(function () {
        ShopSettings::query()->update(['offline_enabled' => false]);
        app(ProductPages::class)->renderAll();
    });
    $this->get("{$site}/cart")->assertNotFound();
});

it('uses a cart-page id that fits the product id column (26 characters)', function () {
    expect(strlen(ProductPages::CART))->toBe(26);
});
