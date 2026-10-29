<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Mail\NewOrderMail;
use Wurk\Shop\Mail\OrderUpdateMail;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\OrderManager;
use Wurk\Shop\Support\OrderMessages;
use Wurk\Shop\Support\OrderPlacer;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Contracts\MediaLibrary;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->subscribe = function (array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.count($modules), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    $this->owner = $this->person();
    $this->member($this->owner, $this->shop);
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->shop, fn () => app(Team::class)->add($this->owner->id, 'Thabo', 'owner'));
    $this->base = $this->app('/kasi-threads/shop');
    $this->tee = ['name' => 'Kasi T-shirt', 'description' => 'Soft cotton.', 'category_id' => null, 'status' => 'published',
        'options' => ['Size', 'Colour'], 'photos' => [],
        'variants' => [
            ['values' => ['Size' => 'M', 'Colour' => 'Red'], 'sku' => 'TEE-M-R', 'price_cents' => 25000, 'compare_at_cents' => 30000, 'stock' => 5],
            ['values' => ['Colour' => 'Red', 'Size' => 'L'], 'sku' => 'TEE-L-R', 'price_cents' => 25000, 'compare_at_cents' => null, 'stock' => 0], // any order
        ]];
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
});

it('is only there for packages with the online shop', function () {
    ($this->subscribe)(['website', 'bookings']);
    expect($this->actingAs($this->owner)->get("{$this->base}/products")->status())->toBeIn([403, 404]);
});

it('saves a product with Size × Colour variants — each its own price, SKU and stock', function () {
    ($this->subscribe)(['website', 'shop']);
    $this->actingAs($this->owner)->post("{$this->base}/products", $this->tee)->assertSessionHas('status', 'Product saved.');
    $this->actingAs($this->owner)->post("{$this->base}/products", $this->tee)->assertSessionHas('status', 'Product saved.'); // same name again

    ($this->in)(function () {
        expect(Product::query()->orderBy('created_at')->pluck('slug')->all())->toBe(['kasi-t-shirt', 'kasi-t-shirt-2']);
        $l = Variant::query()->where('sku', 'TEE-L-R')->first();
        expect($l->label)->toBe('L / Red')->and($l->values)->toBe(['Size' => 'L', 'Colour' => 'Red'])->and($l->stock)->toBe(0); // stored in option order
    });
    $this->actingAs($this->owner)->get("{$this->base}/products")->assertInertia(fn (Assert $p) => $p->component('Shop::Products')->has('products', 2)->where('products.0.variants.0.price_cents', 25000));
});

it('refuses missing values, duplicate combinations and a “was” price that isn’t higher', function () {
    ($this->subscribe)(['website', 'shop']);
    $bad = function (array $variant, string $field, string $message) {
        $this->actingAs($this->owner)->post("{$this->base}/products", [...$this->tee, 'variants' => [$this->tee['variants'][0], $variant]])->assertSessionHasErrors([$field => $message]);
    };
    $bad(['values' => ['Size' => 'L'], 'price_cents' => 100], 'variants.1.values', 'Give every option a value (Size, Colour).');
    $bad(['values' => ['Size' => 'm', 'Colour' => 'red'], 'price_cents' => 100], 'variants.1.values', 'Two variants have the same Size and Colour.');
    $bad(['values' => ['Size' => 'S', 'Colour' => 'Red'], 'price_cents' => 100, 'compare_at_cents' => 100], 'variants.1.compare_at_cents', 'The “was” price must be higher than the price.');
});

it('keeps variant ids when editing (orders will point at them) and removes dropped ones', function () {
    ($this->subscribe)(['website', 'shop']);
    $this->actingAs($this->owner)->post("{$this->base}/products", $this->tee);
    [$id, $m] = ($this->in)(fn () => [Product::query()->sole()->id, Variant::query()->where('sku', 'TEE-M-R')->sole()->id]);
    $this->actingAs($this->owner)->put("{$this->base}/products/{$id}", [...$this->tee, 'variants' => [[...$this->tee['variants'][0], 'id' => $m, 'stock' => 9]]])->assertSessionHas('status', 'Product saved.');
    ($this->in)(fn () => expect(Variant::query()->pluck('id')->all())->toBe([$m])->and(Variant::query()->sole()->stock)->toBe(9));
});

it('adds categories, and keeps products when a category is removed', function () {
    ($this->subscribe)(['website', 'shop']);
    $this->actingAs($this->owner)->post("{$this->base}/categories", ['name' => 'T-shirts'])->assertSessionHas('status', 'Category added.');
    $cat = ($this->in)(fn () => ShopCategory::query()->sole()->id);
    $this->actingAs($this->owner)->post("{$this->base}/products", [...$this->tee, 'category_id' => $cat]);
    $this->actingAs($this->owner)->delete("{$this->base}/categories/{$cat}")->assertSessionHas('status', 'Category removed. Its products are kept.');
    ($this->in)(fn () => expect(Product::query()->sole()->category_id)->toBeNull());
});

it('uploads product photos into the business’s one photo library', function () {
    Storage::fake('local');
    Storage::fake('public');
    ($this->subscribe)(['website', 'shop']);
    $this->actingAs($this->owner)->post("{$this->base}/photos", ['photo' => UploadedFile::fake()->image('tee.jpg', 1200, 1200)])
        ->assertCreated()->assertJsonStructure(['src']);
    $library = $this->actingAs($this->owner)->getJson("{$this->base}/photos")->assertOk()->json();
    expect($library['photos'])->toHaveCount(1)->and($library)->toHaveKeys(['used_mb', 'limit_mb']);
    ($this->in)(fn () => expect(app(MediaLibrary::class)->library()['photos'])->toHaveCount(1)); // the same library Website's editor reads
});

it('saves shop settings, insisting on a way to get orders (paying by EFT is under Settings → Payments)', function () {
    ($this->subscribe)(['website', 'shop']);
    $base = ['collection_enabled' => false, 'collection_address' => '', 'delivery_enabled' => false, 'delivery_fee_cents' => 0, 'delivery_note' => '', 'offline_hold_hours' => 72];
    $this->actingAs($this->owner)->put("{$this->base}/settings", $base)->assertSessionHasErrors(['collection_enabled' => 'Offer collection, delivery, or both.']);
    $this->actingAs($this->owner)->put("{$this->base}/settings", [...$base, 'collection_enabled' => true])->assertSessionHasErrors(['collection_address' => 'Add the address customers collect from.']);
    $this->actingAs($this->owner)->put("{$this->base}/settings", [...$base, 'delivery_enabled' => true, 'delivery_fee_cents' => 6000])
        ->assertSessionHas('status', 'Shop settings saved.');
    ($this->in)(fn () => expect(ShopSettings::query()->sole()->only(['delivery_fee_cents', 'delivery_enabled']))->toBe(['delivery_fee_cents' => 6000, 'delivery_enabled' => true]));
});

it('alerts the people who manage the shop about a new order, and tells the customer as it moves on', function () {
    Mail::fake();
    ($this->subscribe)(['website', 'shop']);
    $order = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id()]);
        app(BusinessPayments::class)->setUpEft('FNB');
        $p = Product::query()->create(['name' => 'Cap', 'slug' => 'cap', 'status' => 'published', 'options' => []]);
        Variant::query()->create(['product_id' => $p->id, 'price_cents' => 12000]);
        $o = app(OrderPlacer::class)->place([['variant' => Variant::query()->sole()->id, 'quantity' => 1]], ['name' => 'Sipho', 'phone' => '082', 'email' => 'sipho@example.com'], 'collection', 'offline');
        app(OrderMessages::class)->newOrder($o);

        return $o;
    });
    Mail::assertSent(NewOrderMail::class, fn ($m) => $m->hasTo('thandi@glow.co.za') && $m->number === 1001 && $m->total === 'R120.00'
        && str_contains($m->how, 'mark it paid') && str_ends_with($m->link, '/kasi-threads/shop/orders/'.$order->id));

    ($this->in)(function () use ($order) {
        foreach (['paid', 'packed', 'ready'] as $step) {
            app(OrderManager::class)->advance($order->refresh(), $step, 'Thabo');
        }
    });
    $subjects = Mail::sent(OrderUpdateMail::class, fn ($m) => $m->hasTo('sipho@example.com'))->map(fn ($m) => $m->envelope()->subject)->values()->all();
    expect($subjects)->toBe(['Payment received — order #1001 · Kasi-threads', 'Ready for collection — order #1001 · Kasi-threads']); // not "packed"
});

it('shows orders by what needs doing, offers only sensible next steps, and records refunds', function () {
    ($this->subscribe)(['website', 'shop']);
    $order = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id()]);
        app(BusinessPayments::class)->setUpEft('FNB');
        $p = Product::query()->create(['name' => 'Cap', 'slug' => 'cap', 'status' => 'published', 'options' => []]);
        Variant::query()->create(['product_id' => $p->id, 'price_cents' => 12000, 'stock' => 4]);

        return app(OrderPlacer::class)->place([['variant' => Variant::query()->sole()->id, 'quantity' => 2]], ['name' => 'Sipho', 'phone' => '082 555 0101'], 'collection', 'offline');
    });
    $this->actingAs($this->owner)->get("{$this->base}/orders?show=awaiting")->assertInertia(fn (Assert $p) => $p->component('Shop::Orders')
        ->where('counts.awaiting', 1)->where('counts.todo', 0)->where('orders.0.number', 1001));
    $this->actingAs($this->owner)->get("{$this->base}/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->component('Shop::Order')
        ->where('order.next', ['paid'])->where('order.can_cancel', true)->where('order.can_refund', false));

    $this->actingAs($this->owner)->post("{$this->base}/orders/{$order->id}/advance", ['to' => 'done'])->assertSessionHasErrors(['order' => 'That step doesn’t follow from where this order is.']);
    $this->actingAs($this->owner)->post("{$this->base}/orders/{$order->id}/advance", ['to' => 'paid'])->assertSessionHas('status', 'Marked as paid.');
    $this->actingAs($this->owner)->get("{$this->base}/orders/{$order->id}")->assertInertia(fn (Assert $p) => $p->where('order.next', ['packed', 'ready'])->where('order.can_refund', true)
        ->where('order.timeline.1.event', 'paid')->where('order.timeline.1.by', 'Thandi Mokoena'));

    $this->actingAs($this->owner)->post("{$this->base}/orders/{$order->id}/refund", ['amount_cents' => 5000, 'restock' => false])->assertSessionHas('status', 'Refund recorded — the customer has been told.');
    $this->actingAs($this->owner)->post("{$this->base}/orders/{$order->id}/refund", ['amount_cents' => 50000, 'restock' => false])->assertSessionHasErrors(['order' => 'Refund up to R190.00 (what’s left of this order).']);
    ($this->in)(fn () => expect($order->refresh()->refunded_cents)->toBe(5000));
});

it('keeps the stock total when a single-branch shop saves a product with an empty per-branch list', function () {
    ($this->subscribe)(['website', 'shop']);
    $payload = ['name' => 'Cap', 'status' => 'published', 'options' => [], 'photos' => [], 'variants' => [['values' => [], 'price_cents' => 12000, 'stock' => 5, 'stock_at' => [], 'prices' => []]]];
    $r = $this->actingAs($this->owner)->post("{$this->base}/products", $payload);
    $r->assertSessionHasNoErrors()->assertSessionHas('status', 'Product saved.');
    ($this->in)(fn () => expect(Variant::query()->sole()->stock)->toBe(5));
});
