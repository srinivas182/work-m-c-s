<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\PlatformPaymentReceived;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class, FakesYoco::class);

beforeEach(function () {
    $this->fakeYoco();
    Http::fake(['payments.yoco.com/api/webhooks' => Http::response(['id' => 'wh_w', 'secret' => 'whsec_'.base64_encode('wurk-secret')])]);
    $this->boss = $this->staffWithRole('super-admin');
    $this->base = $this->admin('/payment-methods');
});

it('lets only staff who manage providers change payment methods', function () {
    $this->actingAs($this->staffWithRole('finance', 'money@wurk.co.za'))->get($this->base)->assertForbidden();
    $this->actingAs($this->boss)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Payments::Admin/PaymentMethods')
        ->where('methods.0', ['provider' => 'yoco', 'name' => 'Yoco', 'available' => true, 'wurk' => null])->has('methods', 5));
});

it('switches a method off for every business', function () {
    $this->createShards(1);
    $shop = $this->createTenant('kasi-threads');
    $this->connectYoco($shop);
    $offered = fn () => app(TenantContext::class)->run($shop, fn () => app(BusinessPayments::class)->available());
    expect($offered())->toBe(['yoco']);
    $this->actingAs($this->boss)->put("{$this->base}/yoco/available", ['available' => false])->assertSessionHas('status', 'Yoco is switched off for every business.');
    expect($offered())->toBe([]);
});

it('keeps WURK’s test and live keys apart, and goes live only with live keys', function () {
    $this->actingAs($this->boss)->put("{$this->base}/yoco/keys/live", ['secret_key' => 'sk_test_abc'])->assertSessionHasErrors(['secret_key' => 'Paste your Yoco live secret key — it starts with sk_live_.']);
    $this->actingAs($this->boss)->put("{$this->base}/yoco/keys/test", ['secret_key' => 'sk_test_abc1234'])->assertSessionHas('status', 'Yoco test keys saved.');
    $this->actingAs($this->boss)->put("{$this->base}/yoco", ['active' => true, 'mode' => 'live'])->assertSessionHasErrors(['mode' => 'Save the live keys first.']);
    $this->actingAs($this->boss)->put("{$this->base}/yoco", ['active' => true, 'mode' => 'test'])->assertSessionHas('status', 'Yoco is on in test mode.');
    expect(PlatformPaymentAccount::query()->find('yoco')->label)->toBe('Key ••••1234')
        ->and((string) DB::table('platform_payment_accounts')->value('test_credentials'))->not->toContain('sk_test_abc1234'); // encrypted

    $this->actingAs($this->boss)->put("{$this->base}/eft/keys/live", ['instructions' => 'WURK (Pty) Ltd, FNB 62000000000'])->assertSessionHas('status', 'EFT / bank transfer live keys saved.');
    $this->actingAs($this->boss)->put("{$this->base}/eft", ['active' => true, 'mode' => 'live']);
    expect(app(PlatformPayments::class)->available())->toBe(['yoco'])->and(app(PlatformPayments::class)->eftInstructions())->toBe('WURK (Pty) Ltd, FNB 62000000000');
});

it('takes a payment to WURK, confirmed only by a signed notification and verified with Yoco', function () {
    Event::fake([PlatformPaymentReceived::class]);
    $this->actingAs($this->boss)->put("{$this->base}/yoco/keys/test", ['secret_key' => 'sk_test_abc1234']);
    $this->actingAs($this->boss)->put("{$this->base}/yoco", ['active' => true, 'mode' => 'test']);
    expect(app(PlatformPayments::class)->start('inv_01J0000000000000000000000A', 59900, 'WURK invoice', ['name' => 'Glow Salon'], ['return' => 'https://x', 'cancel' => 'https://x'], 'yoco'))
        ->toBe('https://c.yoco.com/checkout/ch_1');

    $body = (string) json_encode(['type' => 'payment.succeeded', 'payload' => ['metadata' => ['checkoutId' => 'ch_1']]]);
    $ts = (string) now()->getTimestamp();
    $sig = base64_encode(hash_hmac('sha256', "m1.{$ts}.{$body}", 'wurk-secret', true));
    $this->call('POST', 'http://'.config('wurk.domains.app').'/payments/notify/yoco', [], [], [], ['HTTP_WEBHOOK_ID' => 'm1', 'HTTP_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_WEBHOOK_SIGNATURE' => "v1,{$sig}", 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
    Event::assertDispatched(PlatformPaymentReceived::class, fn ($e) => $e->reference === 'inv_01J0000000000000000000000A');
    expect(app(PlatformPayments::class)->verify('inv_01J0000000000000000000000A'))->toBe(['paid' => true, 'amount_cents' => 59900]);
});
