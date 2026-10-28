<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Models\PaymentAccount;
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
});

it('connects Yoco — the key is tested by registering our webhook — and never shows it again', function () {
    Http::fake(['payments.yoco.com/api/webhooks' => Http::sequence()->push(['message' => 'Unauthorized'], 401)->push(['id' => 'wh_1', 'secret' => 'whsec_c2VjcmV0'])]);
    $this->actingAs($this->owner)->put("{$this->base}/yoco", ['secret_key' => 'pk_test_wrong'])->assertSessionHasErrors('secret_key');
    $this->actingAs($this->owner)->put("{$this->base}/yoco", ['secret_key' => 'sk_test_bad'])->assertSessionHasErrors(['secret_key' => 'Yoco didn’t accept that key. Check it and try again.']);
    $this->actingAs($this->owner)->put("{$this->base}/yoco", ['secret_key' => 'sk_test_good1234'])->assertSessionHas('status', 'Yoco is connected in test mode — no real money moves.');
    Http::assertSent(fn (Request $r) => $r['url'] === 'https://kasi-threads.'.config('wurk.domains.root').'/payments/notify/yoco');

    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Payments::Settings')
        ->where('accounts.0', ['provider' => 'yoco', 'mode' => 'test', 'label' => 'Key ••••1234', 'active' => true]));
    $this->ctx->run($this->shop, fn () => expect(DB::connection(app(TenantContext::class)->connectionName())->table('payment_accounts')->value('credentials'))->not->toContain('sk_test_good1234')); // encrypted
});

it('connects PayFast with a passphrase, and disconnects', function () {
    $this->actingAs($this->owner)->put("{$this->base}/payfast", ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => '', 'mode' => 'test'])
        ->assertSessionHasErrors(['passphrase' => 'Set a passphrase in PayFast (Settings → Integration) and enter it here — it protects your payments.']);
    $this->actingAs($this->owner)->put("{$this->base}/payfast", ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'jt7NOE43FZPn', 'mode' => 'test'])
        ->assertSessionHas('status', 'PayFast is connected in test mode — no real money moves.');
    $this->ctx->run($this->shop, fn () => expect(PaymentAccount::query()->sole()->label)->toBe('Merchant ••••0100'));
    $this->actingAs($this->owner)->delete("{$this->base}/payfast")->assertSessionHas('status', 'PayFast is disconnected. Customers can’t pay with it any more.');
    $this->ctx->run($this->shop, fn () => expect(PaymentAccount::query()->count())->toBe(0));
});
