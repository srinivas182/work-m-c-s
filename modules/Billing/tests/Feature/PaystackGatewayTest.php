<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Wurk\Billing\Gateways\PaystackGateway;
use Wurk\Messaging\Support\ProviderSettings;

/*
| Requests pinned against Paystack's documented API (api.paystack.co): amounts
| in cents, Bearer secret key, reusable authorisations, HMAC-SHA512 webhooks.
*/

beforeEach(function () {
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk_test_1', 'secret_key' => 'sk_test_secret'], [], true, null);
    $this->gateway = app(PaystackGateway::class);
});

it('starts a card-only checkout with our reference and amount in cents', function () {
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc', 'reference' => 'WURK-1']], 200)]);

    expect($this->gateway->checkout('owner@glow.co.za', 34385, 'ZAR', 'WURK-1', 'https://app/return', ['tenant_id' => 't1']))->toBe('https://checkout.paystack.com/abc');
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_test_secret')
        && $r['amount'] === 34385 && $r['currency'] === 'ZAR' && $r['reference'] === 'WURK-1' && $r['channels'] === ['card'] && $r['email'] === 'owner@glow.co.za');
});

it('reads a verified charge, including a reusable card', function () {
    Http::fake(['api.paystack.co/transaction/verify/WURK-1' => Http::response(['status' => true, 'data' => [
        'id' => 4099, 'status' => 'success', 'amount' => 34385, 'currency' => 'ZAR', 'reference' => 'WURK-1',
        'authorization' => ['authorization_code' => 'AUTH_x1', 'reusable' => true, 'signature' => 'SIG_card1', 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030'],
    ]], 200)]);

    $r = $this->gateway->verify('WURK-1');
    expect($r->status)->toBe('succeeded')->and($r->amountCents)->toBe(34385)->and($r->gatewayId)->toBe('4099')
        ->and($r->card)->toBe(['code' => 'AUTH_x1', 'reusable' => true, 'signature' => 'SIG_card1', 'brand' => 'Visa', 'last4' => '4081', 'expires' => '12/2030']);
});

it('charges a saved card and reports declines', function () {
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(['status' => true, 'message' => 'Charge attempted', 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds', 'amount' => 34385, 'currency' => 'ZAR']], 200)]);

    $r = $this->gateway->chargeSaved('AUTH_x1', 'owner@glow.co.za', 34385, 'ZAR', 'WURK-2');
    expect($r->status)->toBe('failed')->and($r->message)->toBe('Insufficient Funds');
    Http::assertSent(fn (Request $r) => $r['authorization_code'] === 'AUTH_x1' && $r['reference'] === 'WURK-2' && $r['amount'] === 34385);
});

it('accepts only correctly signed charge.success webhooks', function () {
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 4099, 'reference' => 'WURK-1']]);
    $good = hash_hmac('sha512', (string) $body, 'sk_test_secret');

    expect($this->gateway->parseWebhook((string) $body, ['x-paystack-signature' => $good]))->toBe(['id' => 'paystack:4099', 'type' => 'charge.success', 'reference' => 'WURK-1'])
        ->and($this->gateway->parseWebhook((string) $body, ['x-paystack-signature' => 'forged']))->toBeNull()
        ->and($this->gateway->parseWebhook((string) json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'x']]), ['x-paystack-signature' => hash_hmac('sha512', (string) json_encode(['event' => 'transfer.success', 'data' => ['reference' => 'x']]), 'sk_test_secret')]))->toBeNull();
});

it('does nothing without credentials, and survives outages', function () {
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', [], [], false, null);
    expect($this->gateway->checkout('a@b.co', 100, 'ZAR', 'R', 'u', []))->toBeNull()
        ->and($this->gateway->verify('R')->status)->toBe('failed');
});
