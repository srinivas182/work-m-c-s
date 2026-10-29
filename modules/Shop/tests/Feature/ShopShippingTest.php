<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Shop\Models\Order;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\Variant;
use Wurk\Shop\Support\OrderManager;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 49900, 'yearly_price_cents' => 499000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    $this->tee = ($this->in)(function () {
        app(BusinessPayments::class)->setUpEft('FNB 62000000000');
        ShippingAccount::query()->create(['provider' => 'bobgo', 'mode' => 'test', 'active' => true, 'test_credentials' => ['api_key' => 'bg_test_key']]);
        ShippingAccount::query()->create(['provider' => 'pargo', 'mode' => 'test', 'active' => true, 'flat_rate_cents' => 6000, 'test_credentials' => ['username' => 'u', 'password' => 'p']]);
        $product = Product::query()->create(['name' => 'Kasi T-shirt', 'slug' => 'kasi-t-shirt', 'status' => 'published', 'options' => []]);

        return Variant::query()->create(['product_id' => $product->id, 'price_cents' => 25000, 'stock' => 5]);
    });
    Http::fake([
        'api.sandbox.bobgo.co.za/v2/rates-at-checkout' => Http::response(['rates' => [['service_level_code' => 'ECO', 'service_name' => 'Economy', 'total_price' => 99.5]]]),
        'api.sandbox.bobgo.co.za/v2/shipments' => Http::response(['id' => 'bg_1', 'tracking_reference' => 'BG123', 'waybill_url' => 'https://bobgo.example/waybill.pdf']),
        'api.staging.pargo.co.za/auth' => Http::response(['access_token' => 'tok']),
        'api.staging.pargo.co.za/orders' => Http::response(['data' => ['id' => 'po_1', 'attributes' => ['orderData' => ['trackingCode' => 'WAY9']]]]),
    ]);
    $this->site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->to = ['street' => '1 Vilakazi St', 'suburb' => 'Orlando West', 'city' => 'Soweto', 'postal_code' => '1804'];
    $this->checkout = fn (array $shipping) => $this->postJson("{$this->site}/cart/checkout", ['lines' => [['variant' => $this->tee->id, 'quantity' => 1]], 'name' => 'Sipho', 'phone' => '082 555 0101',
        'fulfilment' => 'delivery', 'payment' => 'offline', 'consent' => '1', 'shipping' => $shipping]);
});

it('delivers by courier at the courier’s current price, and ships with tracking', function () {
    $this->postJson("{$this->site}/cart/shipping", ['kind' => 'rates', 'lines' => [['variant' => $this->tee->id, 'quantity' => 1]], 'to' => $this->to])
        ->assertOk()->assertJsonPath('rates.0', ['service' => 'ECO', 'name' => 'Economy', 'cents' => 9950, 'eta' => null]);
    ($this->checkout)(['provider' => 'bobgo', 'service' => 'OVERNIGHT', 'to' => $this->to])->assertStatus(409)->assertJsonPath('message', 'That delivery option is no longer available — please choose again.');
    ($this->checkout)(['provider' => 'bobgo', 'service' => 'ECO', 'to' => $this->to])->assertCreated();

    $order = ($this->in)(fn () => Order::query()->sole());
    expect($order->delivery_cents)->toBe(9950)->and($order->total_cents)->toBe(34950)->and($order->shipping_to['city'])->toBe('Soweto');
    ($this->in)(function () use ($order) {
        app(OrderManager::class)->advance($order->refresh(), 'paid', 'Thabo');
        $shipped = app(OrderManager::class)->ship($order->refresh(), 'Thabo');
        expect($shipped->status)->toBe('out_for_delivery')->and($shipped->tracking_reference)->toBe('BG123')->and($shipped->label_url)->toBe('https://bobgo.example/waybill.pdf');
    });
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/shipments') && $r['delivery_address']['city'] === 'Soweto' && $r['custom_order_number'] === 'shop-'.$order->id);
});

it('sends an order to a Pargo pickup point at the flat rate', function () {
    $r = ($this->checkout)(['provider' => 'pargo', 'pickup_point' => 'pup123', 'pickup_point_name' => 'Corner Café']);
    $r->assertCreated();
    $order = ($this->in)(fn () => Order::query()->sole());
    expect($order->delivery_cents)->toBe(6000)->and($order->delivery_address)->toBe('Pargo pickup point: Corner Café');
    ($this->in)(function () use ($order) {
        app(OrderManager::class)->advance($order->refresh(), 'paid', 'Thabo');
        expect(app(OrderManager::class)->ship($order->refresh(), 'Thabo')->tracking_reference)->toBe('WAY9');
    });
});
