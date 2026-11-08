<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->kasi = $this->createTenant('kasi-threads', name: 'Kasi Threads');
    $this->ctx = app(TenantContext::class);
    $this->package = function ($t, array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.Str::lower((string) Str::ulid()), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($t->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    ($this->package)($this->glow, ['website', 'crm', 'referrals']);
    ($this->package)($this->kasi, ['website', 'crm', 'referrals']);
    $this->buy = function ($t, string $name, string $email, int $cents, bool $available) {
        $this->ctx->run($t, function () use ($name, $email, $cents, $available) {
            $order = (string) Str::ulid();
            app(ContactBook::class)->remember($name, null, $email, 'order', $order, null, 'Order');
            event(new PurchaseMade('order', $order, $cents, $cents, null, $available ? now()->subDay() : now()->addDays(10)));
        });
    };
    foreach ([$this->glow, $this->kasi] as $t) {
        $this->ctx->run($t, fn () => ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [500], 'attribution' => 'first', 'link_days' => 30]));
    }
    // Thandi referred Sipho at Glow Salon.
    $this->ctx->run($this->glow, function () {
        $book = app(ContactBook::class);
        $thandi = $book->remember('Thandi Mokoena', null, 'thandi@example.com', 'test', 'seed', null, 'Seed');
        $sipho = $book->remember('Sipho Ndlovu', null, 'sipho@example.com', 'test', 'seed2', null, 'Seed');
        app(Attribution::class)->attach($sipho, app(Attribution::class)->member($thandi)->code);
    });
    ($this->buy)($this->glow, 'Sipho Ndlovu', 'sipho@example.com', 100000, true);   // R1,000 — available
    ($this->buy)($this->glow, 'Sipho Ndlovu', 'sipho@example.com', 40000, false);   // R400 — still in its window
    ($this->buy)($this->glow, 'Thandi Mokoena', 'thandi@example.com', 20000, false); // her own purchase: not a referral sale
    ($this->buy)($this->kasi, 'Lerato Dube', 'lerato@example.com', 500000, true);    // another business on the same shard
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000001', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
    }
});

it('works out every figure from this business alone (hand-checked)', function () {
    // Available: Sipho R100 (10% of R1,000); Thandi R50 (5% of Sipho's R1,000). Pending: Sipho R40, Thandi R20 (5% of R400) + R20 (her own 10%).
    $this->actingAs($this->owner)->get($this->app('/glow-salon/referral-reports'))->assertInertia(fn (Assert $p) => $p->component('Referrals::Reports')
        ->where('cost.sales_cents', 140000)->where('cost.cashback_cents', 16000)->where('cost.referral_cents', 7000)
        ->where('top.0.name', 'Thandi Mokoena')->where('top.0.earned_cents', 7000)->where('top.0.brought', 1)
        ->where('liability.wallets_cents', 15000)->where('liability.pending_cents', 8000)
        ->where('liability.payouts_waiting_cents', 0)->where('liability.expiring_30_days_cents', 0)->where('payouts.paid', 0));
    $this->actingAs($this->staff)->get($this->app('/glow-salon/referral-reports'))->assertStatus(404);
});

it('shows the referral tiles on the home page to the owner only, and only with referrals in the package', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertInertia(fn (Assert $p) => $p->component('Identity::Workspace')
        ->where('tiles.0.label', 'Referral sales (this month)')->where('tiles.0.value', 'R1,400.00')
        ->where('tiles.1.label', 'Wallet liability')->where('tiles.1.value', 'R150.00')->where('tiles.1.hint', 'R80.00 on its way'));
    $this->actingAs($this->staff)->get($this->app('/glow-salon'))->assertInertia(fn (Assert $p) => $p->where('tiles', []));
    // A package without referrals: no tiles, even for the owner.
    $p = app(PackageCatalog::class)->create([
        'key' => 'basic', 'name' => 'Basic', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 2,
        'monthly_price_cents' => 19900, 'yearly_price_cents' => 199000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 0,
        'referral_max_commission_bp' => 0, 'managed_fee_cents' => null, 'modules' => ['website', 'crm'],
    ], null);
    app(Catalog::class)->changePackage($this->glow->id, $p->id, 'monthly');
    app(Entitlements::class)->flush();
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertInertia(fn (Assert $p) => $p->where('tiles', []));
});
