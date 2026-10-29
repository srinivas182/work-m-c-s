<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shipping\Models\ShippingAccount;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $p = app(PackageCatalog::class)->create([
        'key' => 'pro', 'name' => 'Pro', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 10,
        'multi_branch' => true, 'max_branches' => 5, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->shop->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->shop);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->shop, $fn);
    $this->sandton = ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Thandi', 'owner');

        return app(BranchDirectory::class)->add('Sandton', '12 Rivonia Rd', null);
    });
    $this->base = $this->app('/kasi-threads/settings/shipping');
});

it('checks a Bob Go key with Bob Go before saving it, then switches it on', function () {
    Http::fake(['api.sandbox.bobgo.co.za/v2/webhooks' => Http::sequence()->push(['error' => 'unauthorised'], 401)->push([])]);
    $this->actingAs($this->owner)->put("{$this->base}/bobgo/keys/test", ['api_key' => 'wrong'])->assertSessionHasErrors(['api_key' => 'Bob Go didn’t accept that key.']);
    $this->actingAs($this->owner)->put("{$this->base}/bobgo/keys/test", ['api_key' => 'bg_test_1234'])->assertSessionHas('status', 'Bob Go (courier delivery): test details saved.');
    $this->actingAs($this->owner)->put("{$this->base}/bobgo", ['active' => true, 'mode' => 'test'])->assertSessionHas('status', 'Bob Go (courier delivery) is on in test mode.');
    ($this->in)(fn () => expect(ShippingAccount::query()->sole()->only(['provider', 'active', 'label']))->toBe(['provider' => 'bobgo', 'active' => true, 'label' => 'Key …1234']));
});

it('gives a branch its own Pargo, which needs a pickup-point price to switch on', function () {
    Http::fake(['api.staging.pargo.co.za/auth' => Http::response(['access_token' => 'tok'])]);
    $q = "?branch={$this->sandton}";
    $this->actingAs($this->owner)->put("{$this->base}/pargo/keys/test{$q}", ['username' => 'sandton@kasi.co.za', 'password' => 'secret'])->assertSessionHas('status', 'Pargo (pickup points): test details saved.');
    $this->actingAs($this->owner)->put("{$this->base}/pargo{$q}", ['active' => true, 'mode' => 'test'])->assertSessionHasErrors(['flat_rate_cents' => 'Set what customers pay for a pickup point.']);
    $this->actingAs($this->owner)->put("{$this->base}/pargo{$q}", ['active' => true, 'mode' => 'test', 'flat_rate_cents' => 6000])->assertSessionHas('status', 'Pargo (pickup points) is on in test mode.');
    ($this->in)(fn () => expect(ShippingAccount::query()->sole()->branch_id)->toBe($this->sandton)); // only Sandton's; the business has none
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn ($p) => $p->component('Shipping::Settings')->where("providers.1.branches.{$this->sandton}.flat_rate_cents", 6000)->where('providers.1.account', null));
});
