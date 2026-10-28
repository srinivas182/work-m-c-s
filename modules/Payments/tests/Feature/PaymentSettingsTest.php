<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Support\PlatformMethods;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->owner = $this->person();
    $this->member($this->owner, $this->shop);
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->shop, fn () => app(Team::class)->add($this->owner->id, 'Thabo', 'owner'));
    $this->base = $this->app('/kasi-threads/settings/payments');
    $this->in = fn (callable $fn) => $this->ctx->run($this->shop, $fn);
});

it('connects Yoco per mode — the key tested on the spot — and goes live only with live keys', function () {
    Http::fake(['payments.yoco.com/api/webhooks' => Http::sequence()->push(['message' => 'Unauthorized'], 401)->push(['id' => 'wh_1', 'secret' => 'whsec_c2VjcmV0'])]);
    $this->actingAs($this->owner)->put("{$this->base}/yoco/keys/test", ['secret_key' => 'sk_live_wrongmode'])->assertSessionHasErrors(['secret_key' => 'Paste your Yoco test secret key — it starts with sk_test_.']);
    $this->actingAs($this->owner)->put("{$this->base}/yoco/keys/test", ['secret_key' => 'sk_test_bad'])->assertSessionHasErrors(['secret_key' => 'Yoco didn’t accept that key. Check it and try again.']);
    $this->actingAs($this->owner)->put("{$this->base}/yoco/keys/test", ['secret_key' => 'sk_test_good1234'])->assertSessionHas('status', 'Yoco test keys saved.');
    Http::assertSent(fn (Request $r) => ($r['url'] ?? null) === 'https://kasi-threads.'.config('wurk.domains.root').'/payments/notify/yoco');

    $this->actingAs($this->owner)->put("{$this->base}/yoco", ['active' => true, 'mode' => 'live'])->assertSessionHasErrors(['mode' => 'Save the live keys first.']);
    $this->actingAs($this->owner)->put("{$this->base}/yoco", ['active' => true, 'mode' => 'test'])->assertSessionHas('status', 'Yoco is on in test mode — no real money moves.');
    expect(($this->in)(fn () => app(BusinessPayments::class)->available()))->toBe(['yoco']);
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Payments::Settings')
        ->where('methods.0.account', ['active' => true, 'mode' => 'test', 'label' => 'Key ••••1234', 'has_test' => true, 'has_live' => false]));
    ($this->in)(fn () => expect((string) DB::connection(app(TenantContext::class)->connectionName())->table('payment_accounts')->value('test_credentials'))->not->toContain('sk_test_good1234'));
});

it('shows when WURK has switched a method off, and won’t enable it', function () {
    app(PlatformMethods::class)->set('ozow', false);
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->where('methods.2.provider', 'ozow')->where('methods.2.allowed', false));
    $this->actingAs($this->owner)->put("{$this->base}/ozow/keys/test", ['site_code' => 'X', 'private_key' => 'p', 'api_key' => 'a'])->assertNotFound();
});

it('connects PayFast with a passphrase, EFT with instructions, and disconnects', function () {
    $this->actingAs($this->owner)->put("{$this->base}/payfast/keys/test", ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => ''])
        ->assertSessionHasErrors(['passphrase' => 'Set a passphrase in PayFast (Settings → Integration) and enter it here — it protects your payments.']);
    $this->actingAs($this->owner)->put("{$this->base}/payfast/keys/test", ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn'])->assertSessionHas('status', 'PayFast test keys saved.');
    $this->actingAs($this->owner)->put("{$this->base}/eft/keys/live", ['instructions' => 'FNB, account 62000000000'])->assertSessionHas('status', 'EFT / bank transfer live keys saved.');
    $this->actingAs($this->owner)->put("{$this->base}/eft", ['active' => true, 'mode' => 'live'])->assertSessionHas('status', 'EFT / bank transfer is live.');
    ($this->in)(fn () => expect(PaymentAccount::query()->where('provider', 'payfast')->sole()->label)->toBe('Merchant ••••0100'));
    $this->actingAs($this->owner)->delete("{$this->base}/payfast")->assertSessionHas('status', 'PayFast is disconnected. Customers can’t pay with it any more.');
    ($this->in)(fn () => expect(PaymentAccount::query()->pluck('provider')->all())->toBe(['eft']));
});
