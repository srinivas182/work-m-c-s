<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Support\Templates;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Shipping\Contracts\ParcelPlanner;
use Wurk\Tenancy\Contracts\TenantContext;

// Two businesses on ONE shard: each sees and changes only its own settings (v0.14.1 — tables read and written
// with the query builder must filter by business; Eloquent models do it themselves).
uses(InteractsWithShards::class, InteractsWithIdentity::class);

beforeEach(function () {
    $this->createShards(1); // both businesses on the same shard
    $this->a = $this->createTenant('glow-salon');
    $this->b = $this->createTenant('kasi-threads');
    $this->ctx = app(TenantContext::class);
    $this->in = fn ($t, callable $fn) => $this->ctx->run($t, $fn);
    $this->db = fn () => DB::connection($this->ctx->connectionName());
    $this->box = fn (string $name, int $l) => ['id' => (string) Str::ulid(), 'tenant_id' => $this->ctx->id(), 'name' => $name, 'length_mm' => $l, 'width_mm' => 300, 'height_mm' => 200, 'max_weight_g' => 10000, 'position' => 0];
});

it('keeps each business’s boxes and default weight to itself — saving one never deletes another’s', function () {
    ($this->in)($this->a, function () {
        ($this->db)()->table('shipping_boxes')->insert(($this->box)('A box', 400));
        ($this->db)()->table('shipping_preferences')->insert(['tenant_id' => $this->ctx->id(), 'default_item_g' => 200]);
    });
    ($this->in)($this->b, function () {
        // What saving parcels does: replace this business's boxes.
        ($this->db)()->table('shipping_boxes')->where('tenant_id', $this->ctx->id())->delete();
        ($this->db)()->table('shipping_boxes')->insert(($this->box)('B box', 600));
    });
    $plan = fn () => app(ParcelPlanner::class)->plan([['weight_g' => null, 'size_mm' => [100, 100, 100], 'quantity' => 1]]);
    ($this->in)($this->a, fn () => expect($plan())->toBe(['weight_kg' => 0.2, 'size_cm' => ['length' => 40, 'width' => 30, 'height' => 20], 'box' => 'A box', 'fits' => true]));
    ($this->in)($this->b, fn () => expect($plan())->toBe(['weight_kg' => 0.5, 'size_cm' => ['length' => 60, 'width' => 30, 'height' => 20], 'box' => 'B box', 'fits' => true]));
});

it('keeps the auto-reply switch to the business that switched it', function () {
    ($this->in)($this->a, fn () => app(Templates::class)->setEnabled('reply.received', true));
    ($this->in)($this->a, fn () => expect(app(MessageTemplates::class)->enabled('reply.received'))->toBeTrue());
    ($this->in)($this->b, fn () => expect(app(MessageTemplates::class)->enabled('reply.received'))->toBeFalse());
});

it('saving parcels in Settings → Shipping replaces only that business’s boxes', function () {
    $package = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 49900, 'yearly_price_cents' => 499000, 'trial_days' => 14, 'storage_mb' => 2048, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop'],
    ], null);
    $owners = [];
    foreach (['a' => 'glow-salon', 'b' => 'kasi-threads'] as $k => $handle) {
        app(Catalog::class)->subscribe($this->{$k}->id, $package->id, 'monthly', allowTrial: true);
        $owners[$k] = $this->person(['phone' => $k === 'a' ? '+27820000001' : '+27820000002', 'email' => "owner-{$k}@example.com"]);
        $this->member($owners[$k], $this->{$k});
        ($this->in)($this->{$k}, fn () => app(Team::class)->add($owners[$k]->id, 'Owner', 'owner'));
    }
    $save = fn (string $k, string $handle, string $name) => $this->actingAs($owners[$k])->put($this->app("/{$handle}/settings/shipping/parcels"),
        ['default_kg' => 0.5, 'boxes' => [['name' => $name, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'max_kg' => 2]]])->assertSessionHas('status', 'Parcel settings saved.');
    $save('a', 'glow-salon', 'Glow box');
    $save('b', 'kasi-threads', 'Kasi box'); // before v0.14.1 this deleted Glow's box too
    ($this->in)($this->a, fn () => expect(($this->db)()->table('shipping_boxes')->where('tenant_id', $this->ctx->id())->pluck('name')->all())->toBe(['Glow box']));
    $this->actingAs($owners['a'])->get($this->app('/glow-salon/settings/shipping'))->assertInertia(fn ($p) => $p->where('parcels.boxes', fn ($boxes) => collect($boxes)->pluck('name')->all() === ['Glow box']));
});
