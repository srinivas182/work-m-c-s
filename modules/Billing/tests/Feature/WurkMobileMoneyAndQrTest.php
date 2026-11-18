<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Support\Payments;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAttempt;
use Wurk\Payments\Support\GatewayRouter;

/*
 * Sprint 22.4: businesses pay WURK by Capitec Pay, SnapScan, EFT or card through WURK's Paystack account (every channel
 * it allows), or by mobile money and card through Flutterwave, WURK's second gateway. Verified, signed, refundable.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Event::fake([PaymentSucceeded::class]);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    $this->state = ['paystack' => 'abandoned', 'flw' => 'pending', 'amount' => null];
    Http::fake([
        'api.paystack.co/transaction/initialize' => fn (Request $r) => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/ac_1', 'access_code' => 'ac_1']]),
        'api.paystack.co/transaction/verify/*' => fn (Request $r) => Http::response(['status' => true, 'data' => ['status' => $this->state['paystack'], 'reference' => rawurldecode(basename($r->url())), 'amount' => $this->state['amount'], 'currency' => 'ZAR', 'channel' => 'qr']]),
        'api.flutterwave.com/v3/balances' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer FLWSECK_TEST-abc123-X') ? Http::response(['status' => 'success', 'data' => []]) : Http::response(['status' => 'error'], 401),
        'api.flutterwave.com/v3/payments' => Http::response(['status' => 'success', 'data' => ['link' => 'https://checkout.flutterwave.com/v3/hosted/pay/f1']]),
        'api.flutterwave.com/v3/transactions/verify_by_reference*' => fn (Request $r) => Http::response(['status' => 'success', 'data' => ['id' => 4242, 'status' => $this->state['flw'], 'tx_ref' => $r['tx_ref'], 'amount' => ($this->state['amount'] ?? 0) / 100, 'currency' => 'ZAR']]),
        'api.flutterwave.com/v3/transactions/4242/refund' => Http::response(['status' => 'success']),
    ]);
});

it('takes a WURK credit pack by SnapScan through WURK’s Paystack account, with every channel that account allows', function () {
    PlatformPaymentAccount::query()->create(['provider' => 'paystack', 'mode' => 'live', 'active' => true, 'live_credentials' => ['secret_key' => 'sk_live_wurk']]);
    expect(app(PlatformPayments::class)->name('paystack'))->toBe('Capitec Pay, SnapScan, EFT or card');
    $result = app(Payments::class)->checkout($this->glow->id, 'credits', 10000, ['credits' => 100, 'pack' => 'Starter'], 'https://app.wurk.test/glow-salon/settings/billing/return', 'paystack');
    expect($result['url'])->toBe('https://checkout.paystack.com/ac_1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && ! isset($r['channels']) && $r['amount'] === $result['payment']->total_cents);

    $this->state = ['paystack' => 'success', 'flw' => 'pending', 'amount' => $result['payment']->total_cents];
    app(Payments::class)->confirm($result['payment']->reference);
    expect($result['payment']->refresh()->status)->toBe('succeeded');
    Event::assertDispatched(PaymentSucceeded::class);
});

it('adds Flutterwave as WURK’s second gateway: keys tested, a hosted checkout, the secret hash checked, verified before it counts, refunds', function () {
    $boss = $this->staffWithRole('super-admin');
    $admin = $this->admin('/payment-methods');
    $this->actingAs($boss)->put("{$admin}/flutterwave/keys/test", ['secret_key' => 'FLWSECK-live-X', 'secret_hash' => 'a-long-secret-hash-1'])->assertSessionHasErrors('secret_key');
    $this->actingAs($boss)->put("{$admin}/flutterwave/keys/test", ['secret_key' => 'FLWSECK_TEST-nope-X', 'secret_hash' => 'a-long-secret-hash-1'])
        ->assertSessionHasErrors(['secret_key' => 'Flutterwave didn’t accept that key. Check it and try again.']);
    $this->actingAs($boss)->put("{$admin}/flutterwave/keys/test", ['secret_key' => 'FLWSECK_TEST-abc123-X', 'secret_hash' => 'a-long-secret-hash-1'])->assertSessionHas('status', 'Flutterwave test keys saved.');
    $this->actingAs($boss)->put("{$admin}/flutterwave", ['active' => true, 'mode' => 'test'])->assertSessionHasNoErrors();
    $this->actingAs($boss)->put("{$admin}/flutterwave/available", ['available' => true])->assertNotFound(); // never offered by businesses

    expect(app(PlatformPayments::class)->available())->toBe(['flutterwave']);
    $result = app(Payments::class)->checkout($this->glow->id, 'credits', 10000, ['credits' => 100, 'pack' => 'Starter'], 'https://app.wurk.test/glow-salon/settings/billing/return', 'flutterwave');
    expect($result['url'])->toBe('https://checkout.flutterwave.com/v3/hosted/pay/f1');
    $reference = $result['payment']->reference;
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v3/payments') && $r['tx_ref'] === $reference && $r['currency'] === 'ZAR'
        && $r['amount'] === number_format($result['payment']->total_cents / 100, 2, '.', '') && $r['customer']['email'] === 'owner@glow.co.za');

    $hook = fn (string $hash) => $this->call('POST', 'https://'.config('wurk.domains.app').'/payments/notify/flutterwave', [], [], [],
        ['CONTENT_TYPE' => 'application/json', 'HTTP_VERIF_HASH' => $hash], (string) json_encode(['event' => 'charge.completed', 'data' => ['tx_ref' => $reference, 'status' => 'successful']]));
    $hook('wrong')->assertStatus(400);
    $hook('a-long-secret-hash-1')->assertStatus(400); // the hash is right, but Flutterwave hasn't confirmed it
    $this->state = ['paystack' => 'abandoned', 'flw' => 'successful', 'amount' => $result['payment']->total_cents];
    $hook('a-long-secret-hash-1')->assertOk();
    expect($result['payment']->refresh()->status)->toBe('succeeded');

    expect(app(GatewayRouter::class)->refund(PlatformPaymentAccount::query()->find('flutterwave'), PlatformPaymentAttempt::query()->find($reference), 5000))->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transactions/4242/refund') && $r['amount'] === '50.00');
});
