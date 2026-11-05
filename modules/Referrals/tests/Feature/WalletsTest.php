<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->ctx = app(TenantContext::class);
    $this->glow = $this->createTenant('glow-salon');
    $this->kasi = $this->createTenant('kasi-threads');
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    ($this->in)($this->glow, fn () => ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30,
        'max_wallet_bp' => 5000, 'expiry_months' => 12]));
    $this->thandi = ($this->in)($this->glow, fn () => app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'test', 'seed', null, 'Seed'));
    // Thandi buys R800 → 10% back = R80, available after a 14-day window.
    $this->earn = fn (int $cents) => ($this->in)($this->glow, function () use ($cents) {
        $order = (string) Str::ulid();
        app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'order', $order, null, 'Order');
        event(new PurchaseMade('order', $order, $cents, $cents, null, now()->addDays(14)));
    });
    $this->credit = fn () => ($this->in)($this->glow, fn () => app(StoreCredit::class));
});

it('can only spend available rewards, up to the business’s share of an order, and gives the wallet part back on a refund', function () {
    ($this->earn)(80000);
    expect(($this->in)($this->glow, fn () => app(StoreCredit::class)->available($this->thandi)))->toBe(0); // pending: not spendable yet
    $this->travel(15)->days();
    ($this->in)($this->glow, function () {
        $credit = app(StoreCredit::class);
        expect($credit->available($this->thandi))->toBe(8000)
            ->and($credit->usableFor($this->thandi, 10000))->toBe(5000)   // 50% of a R100 order
            ->and($credit->usableFor($this->thandi, 30000))->toBe(8000);  // the whole balance fits
        expect(fn () => $credit->spend($this->thandi, 6000, 'order', 'o-1', 10000))->toThrow(DomainException::class, 'Only R50.00 of the wallet can be used on this order.');
        $credit->spend($this->thandi, 5000, 'order', '01JD000000000000000000OA01', 10000);
        expect($credit->available($this->thandi))->toBe(3000);
        $credit->giveBack('order', '01JD000000000000000000OA01', 3000);
        $credit->giveBack('order', '01JD000000000000000000OA01', 9000); // never more than was spent
        expect($credit->available($this->thandi))->toBe(8000);
    });
    expect(($this->in)($this->kasi, fn () => app(StoreCredit::class)->available($this->thandi)))->toBe(0); // another business on the same shard
});

it('expires unused rewards oldest first — counting what was spent — and only once', function () {
    ($this->earn)(50000);                         // R50, available after 14 days
    $this->travel(15)->days();
    ($this->in)($this->glow, fn () => app(StoreCredit::class)->spend($this->thandi, 2000, 'order', '01JD000000000000000000OB01', 100000)); // spends R20 of it
    ($this->earn)(30000);                         // R30 more, available 14 days later
    $this->travel(12)->months();                  // the first R50 is now over 12 months old; the R30 isn't
    $this->artisan('referrals:expire-wallets')->expectsOutput('Expired: R30.00.')->assertSuccessful(); // R50 − R20 spent
    $this->artisan('referrals:expire-wallets')->expectsOutput('Expired: R0.00.')->assertSuccessful();
    expect(($this->in)($this->glow, fn () => app(StoreCredit::class)->available($this->thandi)))->toBe(3000);
    expect(($this->in)($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('referral_ledger')->where('tenant_id', $this->ctx->id())->where('kind', 'expired')->sum('cents')))->toEqual(-3000);
});

it('lets the owner set the wallet rules, refusing nonsense in plain words', function () {
    $owner = $this->person();
    $this->member($owner, $this->glow);
    ($this->in)($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));
    $url = $this->app('/glow-salon/settings/referrals');
    $rules = ['payouts_allowed' => true, 'min_payout_cents' => 20000, 'max_wallet_bp' => 10000, 'expiry_months' => 0];
    $this->actingAs($owner)->put("{$url}/wallet", $rules)->assertSessionHas('status', 'Wallet rules saved.');
    $this->actingAs($owner)->get($url)->assertInertia(fn ($p) => $p->where('wallet', $rules));
    $this->actingAs($owner)->put("{$url}/wallet", [...$rules, 'max_wallet_bp' => 12000])->assertSessionHasErrors(['max_wallet_bp' => 'A wallet can pay at most the whole order (100%).']);
});
