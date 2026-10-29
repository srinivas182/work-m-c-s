<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Payments\Gateways\PayFast;
use Wurk\Payments\Models\PaymentAccount;
use Wurk\Payments\Models\PaymentAttempt;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Event::fake([BusinessPaymentReceived::class]);
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    [$this->main, $this->sandton] = ($this->in)(function () {
        $sandton = app(BranchDirectory::class)->add('Sandton', null, null);
        PaymentAccount::query()->create(['provider' => 'yoco', 'mode' => 'test', 'active' => true, 'test_credentials' => ['secret_key' => 'sk_test_biz']]); // the business's
        PaymentAccount::query()->create(['provider' => 'payfast', 'branch_id' => $sandton, 'mode' => 'test', 'active' => true,
            'test_credentials' => ['merchant_id' => '10000100', 'merchant_key' => '46f0cd694581a', 'passphrase' => 'sandton-pass']]); // Sandton's own
        PaymentAccount::query()->create(['provider' => 'eft', 'mode' => 'live', 'active' => true, 'live_credentials' => ['instructions' => 'FNB business']]);
        PaymentAccount::query()->create(['provider' => 'eft', 'branch_id' => $sandton, 'mode' => 'live', 'active' => true, 'live_credentials' => ['instructions' => 'Capitec Sandton']]);

        return [app(BranchDirectory::class)->main(), $sandton];
    });
});

it('uses a branch’s own account where it has one, and the business’s otherwise', function () {
    ($this->in)(function () {
        $p = app(BusinessPayments::class);
        expect($p->available($this->main))->toBe(['yoco'])->and($p->available($this->sandton))->toBe(['yoco', 'payfast'])
            ->and($p->eftInstructions($this->main))->toBe('FNB business')->and($p->eftInstructions($this->sandton))->toBe('Capitec Sandton');
    });
});

it('verifies a branch’s payment with that branch’s own account', function () {
    $urls = ['return' => 'https://x/done', 'cancel' => 'https://x/cart'];
    ($this->in)(fn () => app(BusinessPayments::class)->start('ord_s', 25000, 'Order', ['name' => 'Sipho'], $urls, 'payfast', $this->sandton));
    $attempt = ($this->in)(fn () => PaymentAttempt::query()->find('ord_s'));
    expect($attempt->branch_id)->toBe($this->sandton)->and($attempt->form['fields']['notify_url'])->toEndWith('/payments/notify/payfast?branch='.$this->sandton);

    Http::fake(['sandbox.payfast.co.za/eng/query/validate' => Http::response('VALID')]);
    $fields = ['m_payment_id' => 'ord_s', 'pf_payment_id' => '1', 'payment_status' => 'COMPLETE', 'amount_gross' => '250.00'];
    $fields['signature'] = PayFast::signature($fields, 'sandton-pass');
    $site = 'http://kasi-threads.'.config('wurk.domains.root').'/payments/notify/payfast';
    $post = fn (string $url) => $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], http_build_query($fields));
    $post($site)->assertStatus(400); // without the branch: the business has no PayFast account
    $post("{$site}?branch={$this->sandton}")->assertOk();
    Event::assertDispatched(BusinessPaymentReceived::class, fn ($e) => $e->reference === 'ord_s');
});

it('lets an owner give a branch its own account', function () {
    $owner = $this->person();
    $this->member($owner, $this->shop);
    ($this->in)(fn () => app(Team::class)->add($owner->id, 'Thandi', 'owner'));
    $base = $this->app('/kasi-threads/settings/payments');
    $this->actingAs($owner)->put("{$base}/ozow/keys/test?branch={$this->sandton}", ['site_code' => 'SAN-1', 'private_key' => 'pk', 'api_key' => 'ak'])->assertSessionHas('status', 'Ozow test keys saved.');
    $this->actingAs($owner)->put("{$base}/ozow?branch={$this->sandton}", ['active' => true, 'mode' => 'test'])->assertSessionHas('status', 'Ozow is on in test mode — no real money moves.');
    ($this->in)(fn () => expect(app(BusinessPayments::class)->available($this->sandton))->toContain('ozow')->and(app(BusinessPayments::class)->available($this->main))->not->toContain('ozow'));
    $this->actingAs($owner)->get($base)->assertInertia(fn ($p) => $p->where("methods.2.branches.{$this->sandton}.label", 'Site SAN-1')->has('branches', 2));
});
