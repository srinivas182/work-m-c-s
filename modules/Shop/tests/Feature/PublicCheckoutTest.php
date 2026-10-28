<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Mail\OrderPlacedMail;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopSettings;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    $this->entitle = function () {
        $p = app(PackageCatalog::class)->create([
            'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
        ], null);
        app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    [$this->tee, $this->cap] = ($this->in)(function () {
        ShopSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'offline_enabled' => true, 'collection_address' => '12 Vilakazi St, Soweto',
            'offline_instructions' => "FNB Kasi Threads\nAccount 6200 0000 000, branch 250655"]);
        $tee = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);
        $cap = Product::query()->create(['name' => 'Cap', 'slug' => 'cap', 'status' => 'published', 'options' => []]);

        return [Variant::query()->create(['product_id' => $tee->id, 'price_cents' => 25000, 'stock' => 1]), Variant::query()->create(['product_id' => $cap->id, 'price_cents' => 12000, 'stock' => null])];
    });
    $this->site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->order = ['lines' => [['variant' => $this->tee->id, 'quantity' => 1]], 'name' => 'Sipho', 'phone' => '082 555 0101', 'fulfilment' => 'collection', 'consent' => '1'];
});

it('isn’t there without the shop', function () {
    $this->postJson("{$this->site}/cart/price", ['lines' => []])->assertNotFound();
});

it('re-prices the cart with today’s prices, dropping what’s no longer for sale', function () {
    ($this->entitle)();
    ($this->in)(function () {
        $this->tee->update(['price_cents' => 22000]);
        Product::query()->where('slug', 'cap')->update(['status' => 'draft']);
    });
    $this->postJson("{$this->site}/cart/price", ['lines' => [['variant' => $this->tee->id, 'quantity' => 1], ['variant' => $this->cap->id, 'quantity' => 2]]])
        ->assertOk()->assertJsonCount(1, 'lines')->assertJsonPath('lines.0.unit_cents', 22000)->assertJsonPath('subtotal_cents', 22000)
        ->assertJsonPath('checkout.available', true)->assertJsonPath('checkout.collection_address', '12 Vilakazi St, Soweto');
});

it('places the order, and its signed page says what was ordered and how to pay', function () {
    ($this->entitle)();
    $res = $this->postJson("{$this->site}/cart/checkout", $this->order)->assertCreated()->assertJson(['number' => 1001, 'total_cents' => 25000]);
    $this->get($res->json('order_url'))->assertOk()->assertSee('Order #1001')->assertSee('Waiting for payment')
        ->assertSee('1 × Kasi T-shirt')->assertSee('R250.00')->assertSee('Account 6200 0000 000', false)->assertSee('as your reference', false)->assertSee('12 Vilakazi St, Soweto');
    $this->get($res->json('order_url').'x')->assertForbidden();

    $this->postJson("{$this->site}/cart/checkout", $this->order)->assertStatus(409)->assertJson(['message' => 'Kasi T-shirt has just sold out.']);
});

it('emails the customer their order, how to pay and the link', function () {
    Mail::fake();
    ($this->entitle)();
    $url = $this->postJson("{$this->site}/cart/checkout", [...$this->order, 'email' => 'sipho@example.com'])->assertCreated()->json('order_url');
    Mail::assertSent(OrderPlacedMail::class, fn ($m) => $m->hasTo('sipho@example.com') && $m->number === 1001
        && $m->lines === ['1 × Kasi T-shirt — R250.00'] && $m->total === 'R250.00' && str_contains((string) $m->howToPay, '6200 0000 000') && $m->link === $url);
});
