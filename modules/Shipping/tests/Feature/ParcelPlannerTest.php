<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithShards;
use Wurk\Shipping\Contracts\ParcelPlanner;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->shop = $this->createTenant('kasi-threads');
    $this->ctx = app(TenantContext::class);
    $this->plan = fn (array $items) => $this->ctx->run($this->shop, fn () => app(ParcelPlanner::class)->plan($items));
    $this->db = fn () => DB::connection($this->ctx->connectionName());
    $this->item = fn (?int $g, ?array $cm, int $q = 1) => ['weight_g' => $g, 'size_mm' => $cm === null ? null : array_map(fn ($x) => $x * 10, $cm), 'quantity' => $q];
    $this->boxes = fn () => $this->ctx->run($this->shop, function () {
        foreach ([['Small', 200, 150, 100, 2000], ['Medium', 400, 300, 200, 10000]] as $i => [$n, $l, $w, $h, $max]) {
            ($this->db)()->table('shipping_boxes')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->ctx->id(), 'name' => $n, 'length_mm' => $l, 'width_mm' => $w, 'height_mm' => $h, 'max_weight_g' => $max, 'position' => $i]);
        }
    });
});

it('weighs items with the default when they have no weight — unchanged until a business adds weights', function () {
    expect(($this->plan)([($this->item)(null, null, 3)]))->toBe(['weight_kg' => 1.5, 'size_cm' => null, 'box' => null, 'fits' => true]);
    $this->ctx->run($this->shop, fn () => ($this->db)()->table('shipping_preferences')->insert(['tenant_id' => $this->ctx->id(), 'default_item_g' => 200]));
    expect(($this->plan)([($this->item)(null, null, 3), ($this->item)(1250, null)])['weight_kg'])->toBe(1.85);
});

it('picks the smallest box that fits by volume, the largest item and weight — or warns and uses the size-based weight', function () {
    ($this->boxes)();
    expect(($this->plan)([($this->item)(300, [10, 10, 5], 2)]))->toBe(['weight_kg' => 0.6, 'size_cm' => ['length' => 20, 'width' => 15, 'height' => 10], 'box' => 'Small', 'fits' => true])
        ->and(($this->plan)([($this->item)(300, [35, 10, 5])])['box'])->toBe('Medium')          // too long for Small, though Small has the room
        ->and(($this->plan)([($this->item)(3000, [10, 10, 5])])['box'])->toBe('Medium');        // too heavy for Small
    expect(($this->plan)([($this->item)(5000, [60, 50, 40])]))->toBe(['weight_kg' => 24.0, 'size_cm' => null, 'box' => null, 'fits' => false]); // 60×50×40 ÷ 5,000
});
