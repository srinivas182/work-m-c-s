<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Contracts\PlatformPaymentReceived;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PlatformPaymentAccount;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 22.1: Paystack on the business's own account — card, EFT, Capitec Pay and SnapScan on one hosted checkout,
 * the owner choosing which; signed webhooks, every payment verified with Paystack, refunds. WURK can use it too.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Event::fake([BusinessPaymentReceived::class, PlatformPaymentReceived::class]);
    $this->createShards(1);
    $this->shop = $this->createTenant('glow-salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->shop);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->base = $this->app('/glow-salon/settings/payments');
    $this->site = 'http://glow-salon.'.config('wurk.domains.root');
    $this->urls = ['return' => 'https://glow.example/done', 'cancel' => 'https://glow.example/book'];
    // Recorded Paystack answers; the transaction's state can change during a test.
    $this->state = ['status' => 'abandoned', 'amount' => 20000, 'currency' => 'ZAR'];
    Http::fake([
        'api.paystack.co/balance' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer sk_test_good1234') || $r->hasHeader('Authorization', 'Bearer sk_live_wurk9876')
            ? Http::response(['status' => true, 'data' => [['currency' => 'ZAR', 'balance' => 0]]])
            : Http::response(['status' => false, 'message' => 'Invalid key'], 401),
        'api.paystack.co/transaction/initialize' => fn (Request $r) => Http::response(['status' => true, 'data' => [
            'authorization_url' => 'https://checkout.paystack.com/ac_'.$r['reference'], 'access_code' => 'ac_'.$r['reference'], 'reference' => $r['reference']]]),
        'api.paystack.co/transaction/verify/*' => fn (Request $r) => Http::response(['status' => true, 'data' => [
            'status' => $this->state['status'], 'reference' => rawurldecode(basename($r->url())), 'amount' => $this->state['amount'], 'currency' => $this->state['currency']]]),
        'api.paystack.co/refund' => Http::response(['status' => true, 'data' => ['status' => 'pending']]),
    ]);
});

function paystackWebhook($test, string $url, array $event, string $secret): TestResponse
{
    $body = (string) json_encode($event);

    return $test->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, $secret)], $body);
}

it('connects the owner’s Paystack account, testing the key on the spot', function () {
    $this->actingAs($this->owner)->put("{$this->base}/paystack/keys/test", ['secret_key' => 'sk_live_wrongmode'])
        ->assertSessionHasErrors(['secret_key' => 'Paste your Paystack test secret key — it starts with sk_test_.']);
    $this->actingAs($this->owner)->put("{$this->base}/paystack/keys/test", ['secret_key' => 'sk_test_nope'])
        ->assertSessionHasErrors(['secret_key' => 'Paystack didn’t accept that key. Check it (Settings → API Keys & Webhooks in Paystack) and try again.']);
    $this->actingAs($this->owner)->put("{$this->base}/paystack/keys/test", ['secret_key' => 'sk_test_good1234'])->assertSessionHas('status', 'Paystack test keys saved.');
    $this->actingAs($this->owner)->put("{$this->base}/paystack", ['active' => true, 'mode' => 'test'])->assertSessionHas('status', 'Paystack is on in test mode — no real money moves.');

    expect(($this->in)(fn () => app(BusinessPayments::class)->available()))->toBe(['paystack'])
        ->and(($this->in)(fn () => PaymentAccount::query()->sole()->label))->toBe('Key ••••1234');
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Payments::Settings')
        ->where('methods.4.provider', 'paystack')
        ->where('methods.4.channels', [['key' => 'card', 'label' => 'Card'], ['key' => 'eft', 'label' => 'EFT'], ['key' => 'capitec_pay', 'label' => 'Capitec Pay'], ['key' => 'qr', 'label' => 'SnapScan (QR code)']])
        ->where('methods.4.webhook', 'https://glow-salon.'.config('wurk.domains.root').'/payments/notify/paystack')
        ->where('methods.4.account.channels', [])
        ->missing('methods.4.account.test_credentials'));
});

it('lets the owner choose the ways to pay (only those Paystack offers here), and the checkout shows just those', function () {
    $this->actingAs($this->owner)->put("{$this->base}/paystack/channels", ['channels' => ['qr']])->assertSessionHasErrors(['channels' => 'Save your Paystack keys first.']);
    ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'paystack', 'mode' => 'test', 'active' => true, 'test_credentials' => ['secret_key' => 'sk_test_good1234']]));
    $this->actingAs($this->owner)->put("{$this->base}/paystack/channels", ['channels' => ['mobile_money']])->assertSessionHasErrors('channels.0');
    $this->actingAs($this->owner)->put("{$this->base}/paystack/channels", ['channels' => ['qr', 'capitec_pay', 'qr']])
        ->assertSessionHas('status', 'Customers can pay by Capitec Pay, SnapScan (QR code).');

    expect(($this->in)(fn () => app(BusinessPayments::class)->start('dep_1', 20000, 'Deposit', ['name' => 'Thandi', 'email' => 'thandi@example.com'], $this->urls)))
        ->toBe('https://checkout.paystack.com/ac_dep_1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['channels'] === ['capitec_pay', 'qr']
        && $r['amount'] === 20000 && $r['currency'] === 'ZAR' && $r['email'] === 'thandi@example.com' && $r['callback_url'] === 'https://glow.example/done'
        && $r->hasHeader('Authorization', 'Bearer sk_test_good1234'));

    // None ticked: every way the account allows; a customer without an email still gets a checkout.
    $this->actingAs($this->owner)->put("{$this->base}/paystack/channels", ['channels' => []])->assertSessionHas('status', 'Customers see every way to pay your Paystack account allows.');
    ($this->in)(fn () => app(BusinessPayments::class)->start('dep_2', 20000, 'Deposit', ['name' => 'Thandi'], $this->urls));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['reference'] === 'dep_2' && ! isset($r['channels'])
        && str_ends_with((string) $r['email'], '@customers.wurk.co.za'));
});

it('counts a payment only once Paystack verifies it: forged webhooks, unpaid or short payments are refused', function () {
    ($this->in)(fn () => PaymentAccount::query()->create(['provider' => 'paystack', 'mode' => 'test', 'active' => true, 'test_credentials' => ['secret_key' => 'sk_test_good1234']]));
    ($this->in)(fn () => app(BusinessPayments::class)->start('dep_9', 20000, 'Deposit', ['name' => 'Thandi'], $this->urls));
    $hook = "{$this->site}/payments/notify/paystack";
    $paid = ['event' => 'charge.success', 'data' => ['reference' => 'dep_9', 'amount' => 20000, 'channel' => 'qr']];

    paystackWebhook($this, $hook, $paid, 'sk_test_forged')->assertStatus(400);
    paystackWebhook($this, $hook, $paid, 'sk_test_good1234')->assertStatus(400); // signed, but Paystack says it isn't paid
    $this->state = ['status' => 'success', 'amount' => 15000, 'currency' => 'ZAR'];
    paystackWebhook($this, $hook, $paid, 'sk_test_good1234')->assertStatus(400); // paid too little
    $this->state = ['status' => 'success', 'amount' => 20000, 'currency' => 'NGN'];
    paystackWebhook($this, $hook, $paid, 'sk_test_good1234')->assertStatus(400); // another currency
    Event::assertNotDispatched(BusinessPaymentReceived::class);

    $this->state = ['status' => 'success', 'amount' => 20000, 'currency' => 'ZAR'];
    paystackWebhook($this, $hook, $paid, 'sk_test_good1234')->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'dep_9');
    expect(($this->in)(fn () => app(BusinessPayments::class)->verify('dep_9')))->toBe(['paid' => true, 'amount_cents' => 20000]);

    expect(($this->in)(fn () => app(BusinessPayments::class)->refund('dep_9', 5000)))->toBeTrue();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/refund') && $r['transaction'] === 'dep_9' && $r['amount'] === 5000);
});

it('lets WURK take payments through its own Paystack account, with every channel that account allows', function () {
    $boss = $this->staffWithRole('super-admin');
    $admin = $this->admin('/payment-methods');
    $this->actingAs($boss)->put("{$admin}/paystack/keys/live", ['secret_key' => 'sk_live_wrong'])->assertSessionHasErrors('secret_key');
    $this->actingAs($boss)->put("{$admin}/paystack/keys/live", ['secret_key' => 'sk_live_wurk9876'])->assertSessionHas('status', 'Paystack live keys saved.');
    $this->actingAs($boss)->put("{$admin}/paystack", ['active' => true, 'mode' => 'live'])->assertSessionHasNoErrors();

    expect(app(PlatformPayments::class)->available())->toBe(['paystack'])
        ->and(app(PlatformPayments::class)->start('inv_1', 49900, 'WURK invoice', ['name' => 'Glow Salon', 'email' => 'owner@glow.example'], $this->urls, 'paystack'))
        ->toBe('https://checkout.paystack.com/ac_inv_1');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/transaction/initialize') && $r['reference'] === 'inv_1' && ! isset($r['channels']));

    $this->state = ['status' => 'success', 'amount' => 49900, 'currency' => 'ZAR'];
    paystackWebhook($this, 'https://'.config('wurk.domains.app').'/payments/notify/paystack', ['event' => 'charge.success', 'data' => ['reference' => 'inv_1']], 'sk_live_wurk9876')->assertOk();
    Event::assertDispatched(PlatformPaymentReceived::class, fn ($e) => $e->reference === 'inv_1');
    expect(PlatformPaymentAccount::query()->find('paystack')->label)->toBe('Key ••••9876');
});
