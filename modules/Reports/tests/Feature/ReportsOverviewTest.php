<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ctx = app(TenantContext::class);
    $this->package = function ($t, array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.Str::lower((string) Str::ulid()), 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => true, 'max_branches' => 3, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($t->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    ($this->package)($this->glow, ['website', 'shop']);
    [$this->main, $this->rosebank] = $this->ctx->run($this->glow, fn () => [app(BranchDirectory::class)->main(), app(BranchDirectory::class)->add('Rosebank', null, null)]);
    $n = 0;
    $order = function (string $branch, int $cents, int $refunded, $paidAt, string $product) use (&$n) {
        $this->ctx->run($this->glow, function () use ($branch, $cents, $refunded, $paidAt, $product, &$n) {
            $id = (string) Str::ulid();
            $db = DB::connection($this->ctx->connectionName());
            $db->table('shop_orders')->insert(['id' => $id, 'tenant_id' => $this->ctx->id(), 'number' => ++$n, 'status' => 'paid', 'payment_method' => 'card', 'fulfilment' => 'collection',
                'customer_name' => 'C', 'customer_phone' => '+27820000000', 'subtotal_cents' => $cents, 'delivery_cents' => 0, 'total_cents' => $cents, 'refunded_cents' => $refunded, 'paid_at' => $paidAt,
                'branch_id' => $branch, 'created_at' => $paidAt, 'updated_at' => $paidAt]);
            $db->table('shop_order_items')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->ctx->id(), 'order_id' => $id, 'variant_id' => (string) Str::ulid(),
                'product_name' => $product, 'unit_cents' => $cents, 'quantity' => 1, 'stock_tracked' => false, 'line_cents' => $cents]);
        });
    };
    $start = now()->startOfMonth();
    $order($this->main, 30000, 0, $start->copy()->addHours(2), 'Braids');
    $order($this->main, 20000, 5000, $start->copy()->addHours(3), 'Braids');
    $order($this->rosebank, 10000, 0, $start->copy()->addHours(4), 'Oil');
    $order($this->main, 20000, 0, $start->copy()->subHours(5), 'Oil'); // the previous period
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->manager = $this->person(['phone' => '+27831000001', 'email' => 'manager@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'manager' => 'branch-manager', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $m = $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
        if ($who === 'manager') {
            $this->ctx->run($this->glow, fn () => app(Team::class)->setBranches($m, [$this->rosebank]));
        }
    }
    $this->travel(1)->days(); // so this month's orders are in the past
});

it('shows the owner shop sales after refunds, compared with the period before, with highlights', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon/reports'))->assertInertia(fn (Assert $p) => $p->component('Reports::Overview')
        ->where('sections.0.key', 'shop.sales')
        ->where('sections.0.metrics.0', ['label' => 'Sales (after refunds)', 'value' => 55000, 'previous' => 20000, 'format' => 'money'])
        ->where('sections.0.metrics.1.value', 3)->where('sections.0.metrics.2.value', 18333)
        ->where('sections.0.rows.data.0', ['Braids', '2', 'R500.00'])
        ->where('highlights', fn ($h) => collect($h)->contains('Shop sales were up 175% on the previous period.') && collect($h)->contains('Your best seller was Braids (2 sold).'))
        ->where('allBranches', true)->has('branches', 2));
    $this->actingAs($this->owner)->get($this->app('/glow-salon/reports?branch='.$this->rosebank))->assertInertia(fn (Assert $p) => $p->where('sections.0.metrics.0.value', 10000));
});

it('limits a branch manager to their branches, refuses staff, and leaves out sections the package lacks', function () {
    $this->actingAs($this->manager)->get($this->app('/glow-salon/reports'))->assertInertia(fn (Assert $p) => $p->where('sections.0.metrics.0.value', 10000)
        ->where('allBranches', false)->where('branches', [['id' => $this->rosebank, 'name' => 'Rosebank']]));
    $this->actingAs($this->manager)->get($this->app('/glow-salon/reports?branch='.$this->main))->assertInertia(fn (Assert $p) => $p->where('sections.0.metrics.0.value', 10000)); // not theirs: ignored
    $this->actingAs($this->staff)->get($this->app('/glow-salon/reports'))->assertForbidden();
    $basic = app(PackageCatalog::class)->create([ // a package without the shop
        'key' => 'basic', 'name' => 'Basic', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 2,
        'monthly_price_cents' => 19900, 'yearly_price_cents' => 199000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => true, 'max_branches' => 3, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 0,
        'referral_max_commission_bp' => 0, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->changePackage($this->glow->id, $basic->id, 'monthly');
    app(Entitlements::class)->flush();
    $this->actingAs($this->owner)->get($this->app('/glow-salon/reports'))->assertInertia(fn (Assert $p) => $p->where('sections', []));
});
