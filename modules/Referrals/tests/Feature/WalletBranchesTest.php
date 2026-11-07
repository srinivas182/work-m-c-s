<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Referrals\Models\ReferralProgramme;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
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
    // Thandi earned R50 at Rosebank (older), then R30 at the main branch — both available.
    [$this->main, $this->rosebank, $this->thandi] = ($this->in)(function () {
        $branches = app(BranchDirectory::class);
        $main = $branches->main();
        $rosebank = $branches->add('Rosebank', null, null);
        ReferralProgramme::query()->create(['enabled' => true, 'self_bp' => 1000, 'level_bp' => [], 'attribution' => 'first', 'link_days' => 30, 'max_wallet_bp' => 10000]);
        $thandi = null;
        foreach ([[$rosebank, 50000, 3], [$main, 30000, 2]] as [$branch, $cents, $daysAgo]) {
            $order = (string) Str::ulid();
            $thandi = app(ContactBook::class)->remember('Thandi', '082 000 0001', null, 'order', $order, $branch, 'Order');
            event(new PurchaseMade('order', $order, $cents, $cents, $branch, now()->subDays($daysAgo)));
        }

        return [$main, $rosebank, $thandi];
    });
    $this->ledger = fn () => ($this->in)(fn () => DB::connection($this->ctx->connectionName())->table('branch_settlements')->where('tenant_id', $this->ctx->id())
        ->where('kind', 'wallet')->get(['from_branch_id', 'to_branch_id', 'amount_cents'])->map(fn ($r) => [$r->from_branch_id, $r->to_branch_id, (int) $r->amount_cents])->all());
});

it('records what the earning branch owes when rewards are spent at another branch — oldest rewards first — and reverses it on give-back', function () {
    $order = (string) Str::ulid();
    ($this->in)(fn () => app(StoreCredit::class)->spend($this->thandi, 6000, 'order', $order, 10000, $this->main)); // R60 at the main branch
    expect(($this->ledger)())->toBe([[$this->rosebank, $this->main, 5000]]); // Rosebank's R50 used first; main's own R10 records nothing

    ($this->in)(fn () => app(StoreCredit::class)->giveBack('order', $order, 3000)); // half given back
    ($this->in)(fn () => app(StoreCredit::class)->giveBack('order', $order, 3000)); // the rest
    expect(($this->ledger)())->toBe([[$this->rosebank, $this->main, 5000], [$this->rosebank, $this->main, -2500], [$this->rosebank, $this->main, -2500]])
        ->and(array_sum(array_column(($this->ledger)(), 2)))->toBe(0); // nothing owed once it's all given back
});

it('records nothing when rewards are spent where they were earned', function () {
    ($this->in)(fn () => app(StoreCredit::class)->spend($this->thandi, 2000, 'order', (string) Str::ulid(), 10000, $this->rosebank)); // R20 of Rosebank's R50, at Rosebank
    expect(($this->ledger)())->toBe([]);
});
