<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Gateways\PayFast;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    Event::fake([BusinessPaymentReceived::class]);
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
    $this->site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->urls = ['return' => 'https://kasi.example/done', 'cancel' => 'https://kasi.example/cart', 'notify' => 'https://kasi.example/payments/notify/x'];
    $this->secret = 'whsec_'.base64_encode('a-very-secret-key');
    $this->connect = fn (string $provider, array $creds, ?string $secret = null) => ($this->in)(fn () => PaymentAccount::query()->create(['provider' => $provider, 'mode' => 'test', 'test_credentials' => $creds, 'webhook_secret' => $secret]));
});

it('offers only connected providers, Yoco first', function () {
    expect(($this->in)(fn () => app(BusinessPayments::class)->available()))->toBe([]);
    ($this->connect)('payfast', ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn']);
    ($this->connect)('yoco', ['secret_key' => 'sk_test_x'], $this->secret);
    expect(($this->in)(fn () => app(BusinessPayments::class)->available()))->toBe(['yoco', 'payfast']);
});

it('pays with Yoco: an idempotent checkout, the status read back, signed webhooks only, refunds', function () {
    Http::fake([
        'payments.yoco.com/api/checkouts' => Http::response(['id' => 'ch_123', 'redirectUrl' => 'https://c.yoco.com/checkout/ch_123']),
        'payments.yoco.com/api/checkouts/ch_123' => Http::response(['id' => 'ch_123', 'status' => 'completed', 'amount' => 25000]),
        'payments.yoco.com/api/checkouts/ch_123/refund' => Http::response(['status' => 'succeeded']),
    ]);
    ($this->connect)('yoco', ['secret_key' => 'sk_test_x'], $this->secret);
    expect(($this->in)(fn () => app(BusinessPayments::class)->start('ord_1', 25000, 'Order #1001', ['name' => 'Sipho'], $this->urls)))->toBe('https://c.yoco.com/checkout/ch_123');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/checkouts') && $r['amount'] === 25000 && $r['currency'] === 'ZAR'
        && $r->hasHeader('Idempotency-Key', 'ord_1') && $r->hasHeader('Authorization', 'Bearer sk_test_x'));

    $body = (string) json_encode(['type' => 'payment.succeeded', 'payload' => ['metadata' => ['checkoutId' => 'ch_123']]]);
    $sign = fn (int $ts) => ['webhook-id' => 'msg_1', 'webhook-timestamp' => (string) $ts,
        'webhook-signature' => 'v1,'.base64_encode(hash_hmac('sha256', "msg_1.{$ts}.{$body}", 'a-very-secret-key', true))];
    $post = fn (array $headers) => $this->call('POST', "{$this->site}/payments/notify/yoco", [], [], [],
        collect($headers)->mapWithKeys(fn ($v, $k) => ['HTTP_'.strtoupper(str_replace('-', '_', $k)) => $v])->all() + ['CONTENT_TYPE' => 'application/json'], $body);

    $post([...$sign(now()->getTimestamp()), 'webhook-signature' => 'v1,forged'])->assertStatus(400);
    $post($sign(now()->subMinutes(10)->getTimestamp()))->assertStatus(400); // too old
    Event::assertNotDispatched(BusinessPaymentReceived::class);
    $post($sign(now()->getTimestamp()))->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'ord_1' && $e->tenantId === $this->shop->id);
    ($this->in)(fn () => expect(PaymentAttempt::query()->find('ord_1')->status)->toBe('paid'));

    expect(($this->in)(fn () => app(BusinessPayments::class)->refund('ord_1')))->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/ch_123/refund') && $r['amount'] === 25000);
});

it('pays with PayFast: signed fields posted from the business’s page; only a fully checked notification counts', function () {
    $creds = ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn'];
    ($this->connect)('payfast', $creds);
    $url = ($this->in)(fn () => app(BusinessPayments::class)->start('ord_2', 89900, 'Order #1002', ['name' => 'Lerato', 'email' => 'l@example.com'], $this->urls));
    $page = $this->get($url)->assertOk()->assertSee('action="https://sandbox.payfast.co.za/eng/process"', false)->assertSee('name="m_payment_id" value="ord_2"', false);
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain('form-action https://sandbox.payfast.co.za/eng/process');
    $form = ($this->in)(fn () => PaymentAttempt::query()->find('ord_2')->form);
    $fields = $form;
    unset($fields['signature']);
    expect($form['amount'])->toBe('899.00')->and($form['signature'])->toBe(PayFast::signature($fields, 'jt7NOE43FZPn'));

    $answer = 'VALID';
    Http::fake(['sandbox.payfast.co.za/eng/query/validate' => function () use (&$answer) {
        return Http::response($answer);
    }]); // once: stubs added later never win
    $itn = function (array $over = [], string $validate = 'VALID') use ($creds, &$answer) {
        $answer = $validate;
        $fields = ['m_payment_id' => 'ord_2', 'pf_payment_id' => '1089250', 'payment_status' => 'COMPLETE', 'amount_gross' => '899.00', 'merchant_id' => '10000100', ...$over];
        $fields['signature'] = $over['signature'] ?? PayFast::signature(array_diff_key($fields, ['signature' => 1]), $creds['passphrase']);

        return $this->call('POST', "{$this->site}/payments/notify/payfast", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    };
    $itn(['signature' => 'forged'])->assertStatus(400);
    $itn(['amount_gross' => '1.00'])->assertStatus(400);   // wrong amount
    $itn([], 'INVALID')->assertStatus(400);                // PayFast didn't send it
    Event::assertNotDispatched(BusinessPaymentReceived::class);
    $itn()->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'ord_2');
    ($this->in)(fn () => expect(app(BusinessPayments::class)->verify('ord_2'))->toBe(['paid' => true, 'amount_cents' => 89900]));
});

it('offers a method only if enabled, allowed by WURK, and with keys for its mode — never manual EFT', function () {
    ($this->connect)('yoco', ['secret_key' => 'sk_test_x'], $this->secret);
    ($this->connect)('payfast', ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn']);
    ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'eft', 'mode' => 'live', 'live_credentials' => ['instructions' => 'FNB 62000000000']]));
    $available = fn () => ($this->in)(fn () => app(BusinessPayments::class)->available());
    expect($available())->toBe(['yoco', 'payfast']); // EFT is paid by hand, not online

    app(PlatformMethods::class)->set('yoco', false); // WURK switches Yoco off
    expect($available())->toBe(['payfast']);
    app(PlatformMethods::class)->set('yoco', true);

    ($this->in)(fn () => PaymentAccount::query()->where('provider', 'payfast')->update(['mode' => 'live'])); // live, but no live keys saved
    expect($available())->toBe(['yoco']);
    ($this->in)(fn () => PaymentAccount::query()->where('provider', 'yoco')->update(['active' => false])); // disabled
    expect($available())->toBe([]);
});
