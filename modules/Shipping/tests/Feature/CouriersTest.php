<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Shipping\Contracts\Couriers;
use Wurk\Shipping\Models\Shipment;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Shipping\Support\ShippingProblem;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    [$this->main, $this->sandton] = ($this->in)(function () {
        $sandton = app(BranchDirectory::class)->add('Sandton', '12 Rivonia Rd, Sandton', null);
        ShippingAccount::query()->create(['provider' => 'bobgo', 'mode' => 'test', 'active' => true, 'test_credentials' => ['api_key' => 'bg_test_key']]);
        ShippingAccount::query()->create(['provider' => 'pargo', 'branch_id' => $sandton, 'mode' => 'test', 'active' => true, 'flat_rate_cents' => 6000,
            'test_credentials' => ['username' => 'shop@kasi.co.za', 'password' => 'secret']]);

        return [app(BranchDirectory::class)->main(), $sandton];
    });
});

it('offers what each branch has set up: its own account, else the business’s', function () {
    ($this->in)(fn () => expect(app(Couriers::class)->options($this->main))->toBe(['courier' => true, 'pickup_points' => false, 'pickup_rate_cents' => null])
        ->and(app(Couriers::class)->options($this->sandton))->toBe(['courier' => true, 'pickup_points' => true, 'pickup_rate_cents' => 6000]));
});

it('gets live Bob Go rates with the business’s key, from the branch’s address, and copes with odd replies', function () {
    Http::fake(['api.sandbox.bobgo.co.za/v2/rates-at-checkout' => Http::sequence()
        ->push(['rates' => [['service_level_code' => 'ECO', 'service_name' => 'Economy', 'total_price' => 99.5, 'max_delivery_date' => '2027-09-20']]])
        ->push(['unexpected' => true])]);
    $to = ['street' => '1 Vilakazi St', 'city' => 'Soweto', 'postal_code' => '1804'];
    ($this->in)(fn () => expect(app(Couriers::class)->rates($this->sandton, $to, 25000, 0.5))->toBe([['service' => 'ECO', 'name' => 'Economy', 'cents' => 9950, 'eta' => '2027-09-20']])
        ->and(app(Couriers::class)->rates($this->sandton, $to, 25000, 0.5))->toBe([])); // a reply we can't read: no rates, no crash
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer bg_test_key') && $r['collection_address']['street_address'] === '12 Rivonia Rd, Sandton');
});

it('lists Pargo pickup points, explains wrong credentials, and records a Pargo shipment against the branch’s account', function () {
    Http::fake([
        'api.staging.pargo.co.za/auth' => Http::sequence()->push(['access_token' => 'tok'])->push(['access_token' => 'tok'])->push(['error' => 'bad'], 401),
        'api.staging.pargo.co.za/pickup_points*' => Http::response(['data' => [['attributes' => ['pargoPointCode' => 'pup123', 'storeName' => 'Corner Café', 'address1' => '5 Main Rd', 'city' => 'Soweto']]]]),
        'api.staging.pargo.co.za/orders' => Http::response(['data' => ['id' => 'po_1', 'attributes' => ['orderData' => ['trackingCode' => 'WAY123', 'orderLabel' => 'https://pargo.example/label.pdf']]]]),
    ]);
    ($this->in)(function () {
        expect(app(Couriers::class)->pickupPoints($this->sandton, 'Soweto'))->toBe([['code' => 'pup123', 'name' => 'Corner Café', 'address' => '5 Main Rd, Soweto']]);
        $sent = app(Couriers::class)->ship('shop-ord1', $this->sandton, ['provider' => 'pargo', 'pickup_point' => 'pup123', 'name' => 'Sipho', 'phone' => '0825550101', 'value_cents' => 25000, 'weight_kg' => 0.5]);
        expect($sent['tracking_reference'])->toBe('WAY123')->and($sent['label_url'])->toBe('https://pargo.example/label.pdf')
            ->and(Shipment::query()->sole()->only(['provider', 'account_branch_id', 'pickup_point', 'tracking_reference']))->toBe(['provider' => 'pargo', 'account_branch_id' => $this->sandton, 'pickup_point' => 'pup123', 'tracking_reference' => 'WAY123']);
        expect(fn () => app(Couriers::class)->pickupPoints($this->sandton, 'Soweto'))->toThrow(ShippingProblem::class, 'Pargo didn’t accept the username and password.');
    });
});
