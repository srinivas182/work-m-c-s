<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithShards;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Gateways\Ozow;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    Event::fake([BusinessPaymentReceived::class]);
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    $this->site = 'http://kasi-threads.'.config('wurk.domains.root');
    $this->urls = ['return' => 'https://kasi.example/done', 'cancel' => 'https://kasi.example/cart'];
    // One stub per address, answering from a changeable state (stubs added later never win).
    $this->state = ['ozow' => 'Pending', 'peach' => '100.396.101', 'amount' => '250.00'];
    Http::fake([
        'api.ozow.com/*' => fn () => Http::response([['transactionReference' => 'ord_o', 'status' => $this->state['ozow'], 'amount' => (float) $this->state['amount']]]),
        'sandbox-dashboard.peachpayments.com/*' => Http::response(['access_token' => 'tok_1']),
        'testsecure.peachpayments.com/v2/checkout/*/refund' => Http::response(['result' => ['code' => '000.000.000']]),
        'testsecure.peachpayments.com/v2/checkout/*/status*' => fn () => Http::response(['result' => ['code' => $this->state['peach']], 'amount' => $this->state['amount']]),
        'testsecure.peachpayments.com/v2/checkout' => Http::response(['checkoutId' => 'pc_1', 'redirectUrl' => 'https://testsecure.peachpayments.com/checkout/pc_1']),
    ]);
});

it('pays with Ozow: a hashed form from the business’s page; a notification counts only once Ozow confirms it', function () {
    ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'ozow', 'mode' => 'test', 'test_credentials' => ['site_code' => 'KAS-001', 'private_key' => 'pk_secret', 'api_key' => 'api_1']]));
    $url = ($this->in)(fn () => app(BusinessPayments::class)->start('ord_o', 25000, 'Order', ['name' => 'Sipho'], $this->urls));
    $page = $this->get($url)->assertOk()->assertSee('Taking you to Ozow')->assertSee('action="https://pay.ozow.com"', false);
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain('form-action https://pay.ozow.com');
    $form = ($this->in)(fn () => PaymentAttempt::query()->find('ord_o')->form['fields']);
    $fields = $form;
    unset($fields['HashCheck']);
    expect($form['Amount'])->toBe('250.00')->and($form['IsTest'])->toBe('true')->and($form['HashCheck'])->toBe(Ozow::hash($fields, 'pk_secret'))
        ->and($form['NotifyUrl'])->toBe('https://kasi-threads.'.config('wurk.domains.root').'/payments/notify/ozow');

    $notify = function (array $over = []) {
        $n = ['SiteCode' => 'KAS-001', 'TransactionId' => 'oz_77', 'TransactionReference' => 'ord_o', 'Amount' => '250.00', 'Status' => 'Complete', 'Optional1' => '', 'Optional2' => '', 'Optional3' => '', 'Optional4' => '', 'Optional5' => '', 'CurrencyCode' => 'ZAR', 'IsTest' => 'true', 'StatusMessage' => ''];
        $n['Hash'] = $over['Hash'] ?? Ozow::hash(array_values($n), 'pk_secret');

        return $this->call('POST', "{$this->site}/payments/notify/ozow", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($n));
    };
    $notify(['Hash' => 'forged'])->assertStatus(400);
    $notify()->assertStatus(400); // the hash is right, but Ozow hasn't confirmed it
    Event::assertNotDispatched(BusinessPaymentReceived::class);
    $this->state['ozow'] = 'Complete';
    $notify()->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'ord_o');
    expect(($this->in)(fn () => app(BusinessPayments::class)->refund('ord_o')))->toBeFalse(); // refunded in Ozow's portal, then recorded
});

it('pays with Peach: hosted checkout, signed notifications, status read back, refunds', function () {
    ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'peach', 'mode' => 'test', 'test_credentials' => ['entity_id' => 'ent_1', 'client_id' => 'cl', 'client_secret' => 'cs', 'merchant_id' => 'm1', 'secret_token' => 'peach_secret']]));
    expect(($this->in)(fn () => app(BusinessPayments::class)->start('ord_p', 25000, 'Order', ['name' => 'Sipho'], $this->urls)))->toBe('https://testsecure.peachpayments.com/checkout/pc_1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v2/checkout') && $r['merchantTransactionId'] === 'ord_p' && $r['amount'] === 250.0 && $r->hasHeader('Authorization', 'Bearer tok_1'));

    $sign = function (array $fields) {
        ksort($fields);

        return hash_hmac('sha256', implode('', array_map(fn ($k, $v) => $k.$v, array_keys($fields), $fields)), 'peach_secret');
    };
    $fields = ['merchantTransactionId' => 'ord_p', 'result.code' => '000.000.000', 'amount' => '250.00'];
    $post = fn (string $signature) => $this->call('POST', "{$this->site}/payments/notify/peach", [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query([...$fields, 'signature' => $signature]));
    $post('forged')->assertStatus(400);
    $post($sign($fields))->assertStatus(400); // signed, but Peach's status isn't a success yet
    $this->state['peach'] = '000.000.000';
    $post($sign($fields))->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'ord_p');
    expect(($this->in)(fn () => app(BusinessPayments::class)->refund('ord_p', 5000)))->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/pc_1/refund') && $r['amount'] === 50.0);
});
