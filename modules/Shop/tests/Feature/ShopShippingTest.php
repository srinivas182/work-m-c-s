<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
    $this->checkout = fn (array $shipping, int $quantity = 1) => $this->postJson("{$this->site}/cart/checkout", ['lines' => [['variant' => $this->tee->id, 'quantity' => $quantity]], 'name' => 'Sipho', 'phone' => '082 555 0101',
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

    // Bob Go reports the delivery: only with the account's webhook secret; then the order is done.
    ($this->in)(fn () => ShippingAccount::query()->where('provider', 'bobgo')->sole()->update(['test_credentials' => ['api_key' => 'bg_test_key', 'webhook_secret' => 'whsec']]));
    $body = json_encode(['tracking_reference' => 'BG123', 'status' => 'delivered']);
    $notify = fn (string $signature) => $this->call('POST', "{$this->site}/shipping/notify/bobgo", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_BOBGO_SIGNATURE' => $signature], $body);
    $notify('forged')->assertStatus(400);
    expect(($this->in)(fn () => $order->refresh()->status))->toBe('out_for_delivery');
    $notify(hash_hmac('sha256', (string) $body, 'whsec'))->assertOk();
    expect(($this->in)(fn () => $order->refresh()->status))->toBe('done');
});

it('sends an order to a Pargo pickup point at the flat rate', function () {
    $orderUrl = ($this->checkout)(['provider' => 'pargo', 'pickup_point' => 'pup123', 'pickup_point_name' => 'Corner Café'])->assertCreated()->json('order_url'); // the customer's signed link
    $order = ($this->in)(fn () => Order::query()->sole());
    expect($order->delivery_cents)->toBe(6000)->and($order->delivery_address)->toBe('Pargo pickup point: Corner Café');
    ($this->in)(function () use ($order) {
        app(OrderManager::class)->advance($order->refresh(), 'paid', 'Thabo');
        expect(app(OrderManager::class)->ship($order->refresh(), 'Thabo')->tracking_reference)->toBe('WAY9');
    });
    $this->get($orderUrl)->assertOk()->assertSee('On its way to your pickup point')->assertSee('Collect from: Corner Café', false)->assertSee('WAY9');
});

it('uses real weights, sizes and the business’s boxes — the same parcel for the quote, the order and the shipment', function () {
    ($this->in)(function () {
        $this->tee->update(['weight_g' => 180, 'length_mm' => 300, 'width_mm' => 250, 'height_mm' => 20]);
        foreach ([['Small', 200, 150, 100, 2000], ['Medium', 400, 300, 200, 10000]] as $i => [$n, $l, $w, $h, $max]) {
            DB::connection(app(TenantContext::class)->connectionName())->table('shipping_boxes')->insert(['id' => (string) Str::ulid(),
                'tenant_id' => app(TenantContext::class)->id(), 'name' => $n, 'length_mm' => $l, 'width_mm' => $w, 'height_mm' => $h, 'max_weight_g' => $max, 'position' => $i]);
        }
    });
    $two = [['variant' => $this->tee->id, 'quantity' => 2]];
    $this->postJson("{$this->site}/cart/shipping", ['kind' => 'rates', 'lines' => $two, 'to' => $this->to])->assertOk();
    ($this->checkout)(['provider' => 'bobgo', 'service' => 'ECO', 'to' => $this->to], 2)->assertCreated();
    ($this->in)(function () {
        $order = Order::query()->sole();
        app(OrderManager::class)->advance($order, 'paid', 'Thabo');
        app(OrderManager::class)->ship($order->refresh(), 'Thabo');
    });
    // A 30 cm tee is too long for Small (20 cm): two go in Medium, 0.36 kg — the same everywhere.
    $box = ['length' => 40, 'width' => 30, 'height' => 20];
    $quotes = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/rates-at-checkout'));
    expect($quotes)->toHaveCount(2); // the quote, and the re-check when the order was placed
    foreach ($quotes as [$r]) {
        expect($r['items'][0]['weight'])->toBe(0.36)->and(array_intersect_key($r['items'][0], $box))->toEqual($box);
    }
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/shipments') && $r['parcels'][0]['weight'] === 0.36 && $r['parcels'][0]['length'] === 40 && $r['parcels'][0]['height'] === 20);
});
