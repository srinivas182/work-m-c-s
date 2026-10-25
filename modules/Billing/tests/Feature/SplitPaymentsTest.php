<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Wurk\Billing\Contracts\DepositPaid;
use Wurk\Billing\Contracts\SplitPayments;
use Wurk\Messaging\Support\ProviderSettings;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
});

it('creates a payout subaccount with no WURK commission, and explains failures plainly', function () {
    Http::fake(['api.paystack.co/subaccount' => Http::sequence()
        ->push(['status' => true, 'data' => ['subaccount_code' => 'ACCT_glow']])
        ->push(['status' => false, 'message' => 'Account number is invalid'], 400)]);
    expect(app(SplitPayments::class)->createSubaccount('Glow Salon', '632005', '1234567890'))->toBe('ACCT_glow');
    Http::assertSent(fn (Request $r) => $r['percentage_charge'] === 0 && $r['settlement_bank'] === '632005');
    expect(fn () => app(SplitPayments::class)->createSubaccount('Glow Salon', '632005', '000'))->toThrow(RuntimeException::class, 'Account number is invalid');
});

it('takes the deposit into the business’s subaccount, the business bearing the fee', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 15000]]),
    ]);
    $url = app(SplitPayments::class)->checkout('sipho@example.com', 15000, 'dep_01', 'https://glow.example/book/return', 'ACCT_glow', ['booking' => 'b1']);
    expect($url)->toBe('https://checkout.paystack.com/abc');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['subaccount'] === 'ACCT_glow' && $r['bearer'] === 'subaccount' && $r['currency'] === 'ZAR' && $r['amount'] === 15000);
    expect(app(SplitPayments::class)->verify('dep_01'))->toBe(['paid' => true, 'amount_cents' => 15000]);
});

it('announces a signed deposit webhook once, and ignores forged ones', function () {
    Event::fake([DepositPaid::class]);
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 9001, 'reference' => 'dep_01']]);
    $url = $this->app('/webhooks/billing/paystack');
    $post = fn (string $sig) => $this->call('POST', $url, [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json'], $body);

    $post('forged')->assertStatus(400);
    $post(hash_hmac('sha512', $body, 'sk_test'))->assertOk();
    $post(hash_hmac('sha512', $body, 'sk_test'))->assertOk(); // Paystack retries
    Event::assertDispatchedTimes(DepositPaid::class, 1);
    Event::assertDispatched(DepositPaid::class, fn (DepositPaid $e) => $e->reference === 'dep_01');
});
