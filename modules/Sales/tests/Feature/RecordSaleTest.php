<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Messaging\Mail\CustomerMessageMail;
use Wurk\Payments\Support\ReturnWindow;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Referrals\Support\Attribution;
use Wurk\Referrals\Support\Commissions;
use Wurk\Sales\Models\Sale;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => true, 'max_branches' => 3, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'crm', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->people = [];
    foreach (['owner' => 'owner', 'till' => 'staff', 'manager' => 'branch-manager', 'other' => 'branch-manager'] as $i => $key) {
        $this->people[$i] = $this->person(['phone' => '+2783100000'.array_search($i, ['owner', 'till', 'manager', 'other'], true), 'email' => $i.'@glow.co.za']);
        $this->member($this->people[$i], $this->glow);
    }
    [$this->main, $this->rosebank] = ($this->in)(function () {
        $branches = app(BranchDirectory::class);
        $main = $branches->main();
        $rosebank = $branches->add('Rosebank', null, null);
        $team = app(Team::class);
        foreach (['owner' => 'owner', 'till' => 'staff', 'manager' => 'branch-manager', 'other' => 'branch-manager'] as $key => $role) {
            $m = $team->add($this->people[$key]->id, ucfirst($key), $role);
            if ($key === 'manager') {
                $team->setBranches($m, [$main]);
            } elseif ($key === 'other') {
                $team->setBranches($m, [$rosebank]);
            }
        }
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 0, 'level_bp' => [1000], 'attribution' => 'first', 'link_days' => 30]);
        $thandi = app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'test', 'seed', null, 'Seed');

        return [$main, $rosebank, 'thandi' => app(Attribution::class)->member($thandi)];
    }) + [];
    $this->thandi = ($this->in)(fn () => app(Attribution::class)->member(app(ContactBook::class)->find('082 000 0001')['id']));
    $this->url = $this->app('/glow-salon/sales');
    $this->sale = ['name' => 'Sipho', 'phone' => '082 555 0101', 'email' => 'sipho@example.com', 'amount' => '450', 'method' => 'cash', 'branch_id' => null];
    // Sipho was referred by Thandi (as through her link).
    ($this->in)(function () {
        $sipho = app(ContactBook::class)->remember('Sipho', '082 555 0101', 'sipho@example.com', 'test', 'seed2', null, 'Seed');
        app(Attribution::class)->attach($sipho, $this->thandi->code);
    });
});

it('records a sale at the till: the customer, a receipt by email, and their referrer’s reward', function () {
    $this->actingAs($this->people['till'])->getJson("{$this->url}/find?phone=0825550101")->assertOk()->assertJsonPath('customer.name', 'Sipho');
    $this->actingAs($this->people['till'])->post($this->url, [...$this->sale, 'branch_id' => $this->main])->assertSessionHas('status', 'Sale recorded.');
    ($this->in)(function () {
        $sale = Sale::query()->sole();
        expect($sale->status)->toBe('recorded')->and($sale->amount_cents)->toBe(45000)->and($sale->recorded_by_name)->not->toBe('')
            ->and(app(Commissions::class)->balance($this->thandi->id)['pending'])->toBe(4500); // 10% of R450
    });
    Mail::assertSent(CustomerMessageMail::class, fn ($m) => $m->hasTo('sipho@example.com') && $m->subjectLine === 'Your receipt — Glow Salon' && str_contains($m->body, 'R450.00'));
});

it('holds sales over the threshold for approval — rewards only once approved; declined earns nothing', function () {
    $this->actingAs($this->people['owner'])->put($this->app('/glow-salon/settings/sales'), ['amount' => '1000'])->assertSessionHas('status', 'Sales over R1,000.00 now need approval.');
    $this->actingAs($this->people['till'])->post($this->url, [...$this->sale, 'amount' => '1500', 'branch_id' => $this->main])
        ->assertSessionHas('status', 'Sale saved — it needs a manager’s approval (over R1,000.00).');
    $this->actingAs($this->people['till'])->post($this->url, [...$this->sale, 'amount' => '2000', 'branch_id' => $this->main]);
    [$first, $second] = ($this->in)(fn () => Sale::query()->orderBy('amount_cents')->get()->all());
    expect($first->status)->toBe('pending')->and(($this->in)(fn () => app(Commissions::class)->balance($this->thandi->id)['pending']))->toBe(0);

    expect($this->actingAs($this->people['till'])->post("{$this->url}/{$first->id}/approve")->status())->toBeIn([403, 404]); // the till can't approve (hidden, like other permissions)
    $this->actingAs($this->people['other'])->post("{$this->url}/{$first->id}/approve")->assertNotFound();               // another branch's manager
    $this->actingAs($this->people['manager'])->post("{$this->url}/{$first->id}/approve")->assertSessionHas('status', 'Sale approved.');
    $this->actingAs($this->people['manager'])->post("{$this->url}/{$second->id}/decline")->assertSessionHas('status', 'Sale declined.');
    expect(($this->in)(fn () => app(Commissions::class)->balance($this->thandi->id)['pending']))->toBe(15000); // only the approved R1,500
    $this->actingAs($this->people['manager'])->post("{$this->url}/{$first->id}/approve")->assertSessionHasErrors(['sale' => 'This sale has already been decided.']);
});

it('refunds within the return window only, never more than was paid', function () {
    $this->actingAs($this->people['owner'])->post($this->url, [...$this->sale, 'branch_id' => $this->main]);
    $sale = ($this->in)(fn () => Sale::query()->sole());
    $this->actingAs($this->people['owner'])->post("{$this->url}/{$sale->id}/refund", ['amount' => '500'])->assertSessionHas('status', 'Refund recorded.');
    expect(($this->in)(fn () => $sale->refresh()->refunded_cents))->toBe(45000) // capped at R450
        ->and(($this->in)(fn () => app(Commissions::class)->balance($this->thandi->id)['pending']))->toBe(0);
    $this->actingAs($this->people['owner'])->post($this->url, [...$this->sale, 'branch_id' => $this->main]);
    $late = ($this->in)(fn () => Sale::query()->where('refunded_cents', 0)->sole());
    ($this->in)(fn () => app(ReturnWindow::class)->set(1)); // a 1-day window: travelling past the 14-day trial would (rightly) send the owner to billing
    $this->travel(2)->days();
    $this->actingAs($this->people['owner'])->post("{$this->url}/{$late->id}/refund", ['amount' => '10'])->assertSessionHasErrors('sale');
    expect(($this->in)(fn () => $late->refresh()->refunded_cents))->toBe(0);
});

it('shows the till today’s sales and tells it what it may do', function () {
    $this->actingAs($this->people['till'])->post($this->url, [...$this->sale, 'branch_id' => $this->main]);
    $this->actingAs($this->people['till'])->get($this->url)->assertInertia(fn ($p) => $p->component('Sales::Record')
        ->where('sales.0.customer', 'Sipho')->where('sales.0.amount_cents', 45000)->where('canApprove', false)->where('canManage', false));
    $this->actingAs($this->people['owner'])->get($this->url)->assertInertia(fn ($p) => $p->where('canApprove', true)->where('canManage', true));
});
