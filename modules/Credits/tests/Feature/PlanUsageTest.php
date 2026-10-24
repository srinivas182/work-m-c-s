<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Models\CreditPack;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 2,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 50, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    CreditPack::query()->create(['name' => '100 credits', 'credits' => 100, 'price_cents' => 4000, 'active' => true, 'display_order' => 1]);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->ctx->run($this->glow, function () {
        app(Team::class)->add($this->owner->id, 'Owner', 'owner');
        app(CreditWallet::class)->grant('purchase', 20000, 'buy:1', 'Purchase');
        app(CreditWallet::class)->charge('sms', 's1', 'SMS');
    });
    $this->page = $this->app('/glow-salon/settings/credits');
});

it('shows plan and usage: credits in rands, next payment, expiry, meters and limits', function () {
    $this->actingAs($this->owner)->get($this->page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Credits::Business')
        ->where('randPerCredit', 0.46)          // R40 for 100 credits, plus 15% VAT
        ->where('included', 50)
        ->where('spentThisMonth', 3)
        ->where('usage.0.credits', 3)
        ->where('meters', fn ($m) => collect($m)->firstWhere('label', 'Team members')['used'] === 1 && collect($m)->firstWhere('label', 'Team members')['included'] === 2
            && collect($m)->firstWhere('label', 'Storage')['included'] === 1024)
        ->where('limits.monthly', null)
        ->where('canManage', true)
        ->whereType('allowanceExpires', 'string')
        ->whereType('nextPayment.total_cents', 'integer'));
});

it('lets those who manage billing set a monthly limit, and downloads the statement', function () {
    $this->actingAs($this->owner)->put("{$this->page}/limits", ['monthly' => 40])->assertSessionHas('status', 'Spending is now limited to 40 credits a month.');
    $this->ctx->run($this->glow, fn () => expect(app(CreditWallet::class)->limits()['monthly_milli'])->toBe(40000));

    $csv = $this->actingAs($this->owner)->get("{$this->page}/statement.csv")->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    expect($csv)->toStartWith('Date,Type,Description,Credits,"Balance after"')->toContain(',debit,SMS,-3.000,');

    $staff = $this->person(['email' => 'staff@glow.co.za', 'phone' => '+27820000002']);
    $this->member($staff, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($staff->id, 'Staff', 'staff'));
    // Staff without billing rights can't change it (the app answers 404 when even viewing isn't allowed).
    expect($this->actingAs($staff)->put("{$this->page}/limits", ['monthly' => 1])->status())->toBeIn([403, 404]);
    $this->ctx->run($this->glow, fn () => expect(app(CreditWallet::class)->limits()['monthly_milli'])->toBe(40000));
});

it('enforces the package’s team-member limit when adding people', function () {
    $this->ctx->run($this->glow, function () {
        $second = $this->person(['email' => 'second@glow.co.za', 'phone' => '+27820000003']);
        app(Team::class)->add($second->id, 'Second', 'staff');                // 2 of 2
        app(Team::class)->add($second->id, 'Second again', 'staff');          // already a member: fine
        expect(fn () => app(Team::class)->add($this->person(['email' => 'third@glow.co.za', 'phone' => '+27820000004'])->id, 'Third', 'staff'))
            ->toThrow(ValidationException::class, 'Your package includes 2 team members. Upgrade to add more.');
    });
});
