<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Gateways\MobileMoney\AirtelMoney;
use Wurk\Payments\Gateways\MobileMoney\MtnMomo;
use Wurk\Payments\Jobs\CheckMobileMoneyPayment;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Payments\Support\MobileMoney;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/*
 * Sprint 22.2: mobile money, direct — M-Pesa (Daraja STK push), MTN MoMo (Request to Pay) and Airtel Money. A prompt
 * on the customer's phone; the result always read back from the provider; plain words when it fails; only where the
 * provider works. Provider answers are recorded sandbox responses.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Event::fake([BusinessPaymentReceived::class]);
    Bus::fake([CheckMobileMoneyPayment::class]);
    $this->createShards(1);
    $this->shop = $this->createTenant('mama-salon');
    $this->shop->forceFill(['country' => 'KE'])->save();
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    $this->urls = ['return' => 'https://mama.example/booked', 'cancel' => 'https://mama.example/book'];
    // Daraja: the STK query answers from a changeable state (null: still being processed).
    $this->daraja = ['result' => null];
    Http::fake([
        'sandbox.safaricom.co.ke/oauth/*' => fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('ck_good:cs_good'))
            ? Http::response(['access_token' => 'tok_daraja', 'expires_in' => '3599']) : Http::response(['errorMessage' => 'Invalid credentials'], 400),
        'sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest' => fn (Request $r) => Http::response(['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_'.$r['AccountReference'], 'ResponseCode' => '0', 'CustomerMessage' => 'Success. Request accepted for processing']),
        'sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query' => fn () => $this->daraja['result'] === null
            ? Http::response(['requestId' => 'r-1', 'errorCode' => '500.001.1001', 'errorMessage' => 'The transaction is being processed'], 500)
            : Http::response(['ResponseCode' => '0', 'ResultCode' => $this->daraja['result'], 'ResultDesc' => 'x']),
    ]);
    $this->mpesa = fn () => ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'mpesa', 'mode' => 'test', 'active' => true,
        'test_credentials' => ['shortcode' => '174379', 'passkey' => 'pk', 'consumer_key' => 'ck_good', 'consumer_secret' => 'cs_good']]));
});

it('reads numbers the way people type them', function () {
    expect(MobileMoney::msisdn('0712 345 678', 'KE'))->toBe('254712345678')
        ->and(MobileMoney::msisdn('+254 712 345 678', 'KE'))->toBe('254712345678')
        ->and(MobileMoney::msisdn('712345678', 'KE'))->toBe('254712345678')
        ->and(MobileMoney::msisdn('0241234567', 'GH'))->toBe('233241234567')
        ->and(MobileMoney::msisdn('12', 'KE'))->toBeNull()
        ->and(MobileMoney::msisdn('0712345678', 'ZA'))->toBeNull();
    // Every provider's currencies match where WURK lets it be offered.
    expect(array_keys(MtnMomo::CURRENCIES))->toEqualCanonicalizing(PlatformMethods::COUNTRIES['mtn_momo'])
        ->and(array_keys(AirtelMoney::CURRENCIES))->toEqualCanonicalizing(PlatformMethods::COUNTRIES['airtel_money']);
});

it('offers each mobile money provider only where it works, and tests the keys when they’re saved', function () {
    $owner = $this->person();
    $this->member($owner, $this->shop);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Wanjiku', 'owner'));
    $base = $this->app('/mama-salon/settings/payments');
    $this->actingAs($owner)->get($base)->assertInertia(fn (Assert $p) => $p->where('methods', fn ($m) => $m->pluck('provider')->all() === ['yoco', 'payfast', 'ozow', 'peach', 'paystack', 'mpesa', 'airtel_money', 'eft']));

    $keys = ['shortcode' => '174379', 'passkey' => 'pk', 'consumer_key' => 'ck_bad', 'consumer_secret' => 'cs_good'];
    $this->actingAs($owner)->put("{$base}/mpesa/keys/test", $keys)->assertSessionHasErrors(['shortcode' => 'M-Pesa didn’t accept those keys. Check them and try again.']);
    $this->actingAs($owner)->put("{$base}/mpesa/keys/test", [...$keys, 'consumer_key' => 'ck_good', 'till' => '5150'])->assertSessionHasErrors('till');
    $this->actingAs($owner)->put("{$base}/mpesa/keys/test", [...$keys, 'consumer_key' => 'ck_good'])->assertSessionHas('status', 'M-Pesa test keys saved.');
    expect(($this->in)(fn () => PaymentAccount::query()->sole()->label))->toBe('Paybill 174379');
    $this->actingAs($owner)->put("{$base}/mtn_momo/keys/test", ['api_user' => 'x'])->assertNotFound(); // MTN doesn't serve Kenya

    // A South African business never sees mobile money.
    $za = $this->createTenant('glow-salon');
    expect(app(TenantContext::class)->run($za, fn () => PlatformMethods::forCountry(app(TenantContext::class)->current()->countryCode())))->not->toContain('mpesa', 'mtn_momo', 'airtel_money');
});

it('pays by M-Pesa: the number, a prompt on the phone, a page that waits, and the booking confirmed once M-Pesa says so', function () {
    ($this->mpesa)();
    expect(($this->in)(fn () => app(BusinessPayments::class)->available()))->toBe(['mpesa']);
    $url = ($this->in)(fn () => app(BusinessPayments::class)->start('dep_k1', 20000, 'Deposit', ['name' => 'Akinyi', 'phone' => '+254712345678'], $this->urls));

    $this->get($url)->assertOk()->assertSee('Pay KES 200.00 with M-Pesa')->assertSee('value="0712345678"', false)
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    $this->post($url, ['phone' => 'not a number'])->assertOk()->assertSee('Enter your mobile money number');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'processrequest'));

    $this->post($url, ['phone' => '0712 345 678'])->assertRedirect($url);
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/processrequest') && $r['PartyA'] === '254712345678' && $r['PhoneNumber'] === '254712345678'
        && $r['Amount'] === 200 && $r['TransactionType'] === 'CustomerPayBillOnline' && $r['BusinessShortCode'] === '174379' && $r->hasHeader('Authorization', 'Bearer tok_daraja')
        && $r['CallBackURL'] === 'https://mama-salon.'.config('wurk.domains.root').'/payments/notify/mpesa');
    Bus::assertDispatched(CheckMobileMoneyPayment::class, fn ($j) => $j->reference === 'dep_k1');

    $this->get($url)->assertOk()->assertSee('Check your phone')->assertSee('<meta http-equiv="refresh" content="4">', false);
    // An unsigned callback saying "paid" is only a hint: M-Pesa still says it's being processed.
    $site = 'http://mama-salon.'.config('wurk.domains.root');
    $callback = ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm-1', 'CheckoutRequestID' => 'ws_CO_dep_k1', 'ResultCode' => 0, 'ResultDesc' => 'ok']]];
    $this->postJson("{$site}/payments/notify/mpesa", $callback)->assertStatus(400);
    Event::assertNotDispatched(BusinessPaymentReceived::class);

    $this->daraja['result'] = '0';
    $this->postJson("{$site}/payments/notify/mpesa", $callback)->assertOk();
    $this->get($url)->assertRedirect('https://mama.example/booked');
    (new CheckMobileMoneyPayment($this->shop->id, 'dep_k1'))->handle(app(TenantDirectory::class), app(TenantContext::class));
    Event::assertDispatchedTimes(BusinessPaymentReceived::class, 1);
    expect(($this->in)(fn () => app(BusinessPayments::class)->verify('dep_k1')))->toBe(['paid' => true, 'amount_cents' => 20000])
        ->and(($this->in)(fn () => app(BusinessPayments::class)->refund('dep_k1')))->toBeFalse(); // refunded in M-Pesa's portal
});

it('says plainly when the customer declines, and lets them try again without taking the money twice', function () {
    ($this->mpesa)();
    $url = ($this->in)(fn () => app(BusinessPayments::class)->start('dep_k2', 20000, 'Deposit', ['name' => 'Akinyi'], $this->urls));
    $this->post($url, ['phone' => '0712345678']);
    $this->daraja['result'] = '1032';
    $this->get($url)->assertOk()->assertSee('The payment was declined on your phone')->assertSee('Try again');

    $this->daraja['result'] = null;
    $this->travel(31)->seconds();
    $this->post($url, ['phone' => '0712345678'])->assertRedirect($url);
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/processrequest')))->toHaveCount(2);
});

it('times out a prompt nobody answers, and never floods a phone with prompts', function () {
    ($this->mpesa)();
    $url = ($this->in)(fn () => app(BusinessPayments::class)->start('dep_k3', 20000, 'Deposit', ['name' => 'Akinyi'], $this->urls));
    $this->post($url, ['phone' => '0712345678']);
    $this->post($url, ['phone' => '0712345678']); // within 30 seconds: no second prompt
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/processrequest')))->toHaveCount(1);

    $this->travel(MobileMoney::EXPIRES_SECONDS + 1)->seconds();
    $this->get($url)->assertSee('Your phone didn’t answer in time');
    foreach (range(1, 6) as $i) {
        $this->travel(31)->seconds();
        $this->daraja['result'] = '1037';
        $this->post($url, ['phone' => '0712345678']);
    }
    expect(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/processrequest')))->toHaveCount(MobileMoney::MAX_PROMPTS);
});

it('reads MTN MoMo and Airtel Money results back from the provider, in plain reasons', function () {
    $this->shop->forceFill(['country' => 'UG'])->save();
    Http::fake([
        'sandbox.momodeveloper.mtn.com/collection/token/' => Http::response(['access_token' => 'tok_momo', 'expires_in' => 3600]),
        'sandbox.momodeveloper.mtn.com/collection/v1_0/requesttopay' => Http::response(null, 202),
        'sandbox.momodeveloper.mtn.com/collection/v1_0/requesttopay/*' => Http::sequence()
            ->push(['status' => 'PENDING'])
            ->push(['status' => 'FAILED', 'reason' => 'NOT_ENOUGH_FUNDS'])
            ->push(['status' => 'SUCCESSFUL', 'amount' => '200', 'currency' => 'EUR', 'externalId' => 'dep_u1', 'financialTransactionId' => 'f-1']),
        'openapiuat.airtel.africa/auth/oauth2/token' => Http::response(['access_token' => 'tok_airtel', 'expires_in' => 180]),
        'openapiuat.airtel.africa/merchant/v1/payments/' => Http::response(['status' => ['success' => true, 'code' => '200']]),
        'openapiuat.airtel.africa/standard/v1/payments/*' => Http::response(['data' => ['transaction' => ['status' => 'TF', 'message' => 'Insufficient funds']], 'status' => ['success' => true]]),
    ]);
    ($this->in)(function () {
        PaymentAccount::query()->create(['provider' => 'mtn_momo', 'mode' => 'test', 'active' => true, 'test_credentials' => ['api_user' => '3f2b6c1e-0000-4000-8000-000000000001', 'api_key' => 'k', 'subscription_key' => 'sub']]);
        PaymentAccount::query()->create(['provider' => 'airtel_money', 'mode' => 'test', 'active' => true, 'test_credentials' => ['client_id' => 'cid', 'client_secret' => 'cs']]);
    });
    $mm = fn () => app(MobileMoney::class);

    ($this->in)(function () use ($mm) {
        app(BusinessPayments::class)->start('dep_u1', 20000, 'Deposit', ['name' => 'Nakato'], $this->urls, 'mtn_momo');
        expect($mm()->prompt(PaymentAttempt::query()->find('dep_u1'), '0772 123 456'))->toBeNull()
            ->and($mm()->check(PaymentAttempt::query()->find('dep_u1'))->state)->toBe('pending')
            ->and($mm()->check(PaymentAttempt::query()->find('dep_u1'))->reason)->toBe('funds');
    });
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/requesttopay') && $r['payer'] === ['partyIdType' => 'MSISDN', 'partyId' => '256772123456']
        && $r['amount'] === '200' && $r['currency'] === 'EUR' && $r->hasHeader('X-Target-Environment', 'sandbox') && $r->hasHeader('X-Reference-Id'));

    ($this->in)(function () use ($mm) {
        app(BusinessPayments::class)->start('dep_u2', 20000, 'Deposit', ['name' => 'Nakato'], $this->urls, 'airtel_money');
        expect($mm()->prompt(PaymentAttempt::query()->find('dep_u2'), '0752 123 456'))->toBeNull()
            ->and($mm()->check(PaymentAttempt::query()->find('dep_u2'))->reason)->toBe('funds');
    });
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/merchant/v1/payments/') && $r['subscriber']['msisdn'] === '752123456'
        && $r['transaction']['currency'] === 'UGX' && $r->hasHeader('X-Country', 'UG'));
});
