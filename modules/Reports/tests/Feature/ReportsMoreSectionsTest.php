<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Crm\Contracts\PurchaseRefunded;
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
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $start = now()->startOfMonth();
    $this->ctx->run($this->glow, function () use ($start) {
        $db = DB::connection($this->ctx->connectionName());
        $t = $this->ctx->id();
        $main = app(BranchDirectory::class)->main();
        // Takings: R115 order + R230 sale − R15 refund.
        event(new PurchaseMade('order', 'o1', 11500, 11500, $main, now()));
        event(new PurchaseMade('sale', 's1', 23000, 23000, $main, now()));
        event(new PurchaseRefunded('order', 'o1', 1500));
        // Leads: 3 new (2 form, 1 manual); first replies after 2h and 4h; 1 won and 1 lost closed this month.
        $pipeline = (string) Str::ulid();
        $db->table('pipelines')->insert(['id' => $pipeline, 'tenant_id' => $t, 'name' => 'Sales', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $stage = fn (string $kind) => tap((string) Str::ulid(), fn ($id) => $db->table('pipeline_stages')->insert(['id' => $id, 'tenant_id' => $t, 'pipeline_id' => $pipeline, 'name' => $kind, 'kind' => $kind, 'position' => 0]));
        [$open, $won, $lost] = [$stage('open'), $stage('won'), $stage('lost')];
        foreach ([['form', $won, 2], ['form', $lost, 4], ['manual', $open, null]] as $i => [$source, $st, $reply]) {
            $id = (string) Str::ulid();
            $created = $start->copy()->addHours(1 + $i);
            $db->table('leads')->insert(['id' => $id, 'tenant_id' => $t, 'contact_id' => (string) Str::ulid(), 'pipeline_id' => $pipeline, 'stage_id' => $st, 'title' => 'Lead',
                'source' => $source, 'closed_at' => $st === $open ? null : $created->copy()->addDay(), 'created_at' => $created, 'updated_at' => $created]);
            $db->table('lead_events')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'lead_id' => $id, 'event' => 'created', 'at' => $created]);
            if ($reply !== null) {
                $db->table('lead_events')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'lead_id' => $id, 'event' => 'moved', 'at' => $created->copy()->addHours($reply)]);
            }
        }
        // Stock: Oil at 0 (out), Braids at 3 (sold this month), Wax at 10 (didn't sell).
        $variants = [];
        foreach (['Oil' => 0, 'Braids' => 3, 'Wax' => 10] as $name => $qty) {
            $product = (string) Str::ulid();
            $db->table('shop_products')->insert(['id' => $product, 'tenant_id' => $t, 'name' => $name, 'slug' => strtolower($name), 'created_at' => now(), 'updated_at' => now()]);
            $variants[$name] = (string) Str::ulid();
            $db->table('shop_variants')->insert(['id' => $variants[$name], 'tenant_id' => $t, 'product_id' => $product, 'price_cents' => 10000, 'created_at' => now(), 'updated_at' => now()]);
            $db->table('shop_stock')->insert(['variant_id' => $variants[$name], 'branch_id' => $main, 'tenant_id' => $t, 'quantity' => $qty]);
        }
        $order = (string) Str::ulid();
        $db->table('shop_orders')->insert(['id' => $order, 'tenant_id' => $t, 'number' => 1, 'status' => 'paid', 'payment_method' => 'card', 'fulfilment' => 'collection', 'customer_name' => 'C',
            'customer_phone' => '+27820000000', 'subtotal_cents' => 10000, 'delivery_cents' => 0, 'total_cents' => 10000, 'paid_at' => $start->copy()->addHours(2), 'branch_id' => $main, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('shop_order_items')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'order_id' => $order, 'variant_id' => $variants['Braids'], 'product_name' => 'Braids',
            'unit_cents' => 10000, 'quantity' => 1, 'stock_tracked' => true, 'line_cents' => 10000]);
    });
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
    }
    $this->travel(1)->days();
    $this->section = fn (string $key) => collect($this->actingAs($this->owner)->get($this->app('/glow-salon/reports'))->viewData('page')['props']['sections'])->firstWhere('key', $key);
});

it('reports takings, and VAT only once the business says it’s VAT-registered', function () {
    $t = ($this->section)('takings');
    expect($t['metrics'])->toHaveCount(1)->and($t['metrics'][0]['value'])->toBe(33000)->and($t['note'])->toContain('once you tell us you’re VAT-registered');
    $this->actingAs($this->staff)->get($this->app('/glow-salon/settings/vat'))->assertNotFound(); // pages they may not use don't reveal themselves
    $this->actingAs($this->owner)->put($this->app('/glow-salon/settings/vat'), ['vat_registered' => true, 'vat_number' => ''])->assertSessionHasErrors(['vat_number' => 'Your VAT number.']);
    $this->actingAs($this->owner)->put($this->app('/glow-salon/settings/vat'), ['vat_registered' => true, 'vat_number' => '4123456789'])->assertSessionHas('status', 'Saved.');
    $t = ($this->section)('takings');
    expect(array_column($t['metrics'], 'value', 'label'))->toBe(['Taken (after refunds)' => 33000, 'VAT included (15%)' => 4304, 'Taken excluding VAT' => 28696]);
});

it('reports leads — sources, conversion and median first response — and stock right now', function () {
    $l = ($this->section)('leads');
    expect(array_column($l['metrics'], 'value', 'label'))->toBe(['New leads' => 3, 'Won (of those closed)' => 50.0, 'First response (hours, median)' => 3.0])
        ->and($l['rows']['data'])->toBe([['Website forms', '2'], ['Added by the team', '1']])
        ->and($l['highlights'])->toBe(['You answered new leads in 3 hours on average (median).']);
    $s = ($this->section)('shop.stock');
    expect(array_column($s['metrics'], 'value', 'label'))->toBe(['Out of stock' => 1, 'Didn’t sell this period' => 1, 'Items in stock' => 13])
        ->and($s['metrics'][0]['snapshot'])->toBeTrue()
        ->and(array_column($s['rows']['data'], 0))->toBe(['Oil', 'Braids', 'Wax'])
        ->and($s['extra']['data'])->toBe([['Wax', '10']])
        ->and($s['highlights'])->toBe(['1 item is out of stock.']);
});
