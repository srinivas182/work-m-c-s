<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Shop\Support\CheckoutProblem;
use Wurk\Shop\Support\OrderManager;
use Wurk\Shop\Support\OrderPlacer;
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
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'shop', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->variant = $this->ctx->run($this->glow, function () {
        $db = DB::connection($this->ctx->connectionName());
        $product = (string) Str::ulid();
        $variant = (string) Str::ulid();
        $db->table('shop_products')->insert(['id' => $product, 'tenant_id' => $this->ctx->id(), 'name' => 'Hair oil', 'slug' => 'hair-oil', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $db->table('shop_variants')->insert(['id' => $variant, 'tenant_id' => $this->ctx->id(), 'product_id' => $product, 'price_cents' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        app(BusinessPayments::class)->setUpEft('FNB, account 62000000000'); // the shop takes orders (Settings → Payments)

        return $variant;
    });
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    foreach (['owner' => 'owner', 'staff' => 'staff'] as $who => $role) {
        $this->member($this->$who, $this->glow);
        $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->$who->id, ucfirst($who), $role));
    }
    $this->base = $this->app('/glow-salon/coupons');
    $this->actingAs($this->owner)->post($this->base, ['code' => 'save10', 'kind' => 'percent', 'value' => 10, 'applies_to' => 'both', 'max_uses' => 2, 'once_per_customer' => true])
        ->assertSessionHas('status', 'Coupon SAVE10 is ready.');
    $this->actingAs($this->owner)->post($this->base, ['code' => 'BIG50', 'kind' => 'amount', 'value' => 50, 'applies_to' => 'shop', 'min_spend' => 300]);
    $this->order = fn (string $phone, int $qty = 2, ?string $code = 'SAVE10') => $this->ctx->run($this->glow, fn () => app(OrderPlacer::class)->place(
        [['variant' => $this->variant, 'quantity' => $qty]], ['name' => 'Customer', 'phone' => $phone, 'email' => null], 'collection', 'offline', null, null, null, $code));
});

it('manages coupons — owners only, codes unique', function () {
    $this->actingAs($this->owner)->post($this->base, ['code' => 'Save10', 'kind' => 'amount', 'value' => 5, 'applies_to' => 'shop'])->assertSessionHasErrors(['code' => 'You already have that code.']);
    $this->actingAs($this->owner)->post($this->base, ['code' => 'X', 'kind' => 'percent', 'value' => 150, 'applies_to' => 'shop'])->assertSessionHasErrors(['value' => 'A percentage can’t be more than 100.']);
    $this->actingAs($this->staff)->get($this->base)->assertNotFound();
});

it('quotes a code in the cart exactly as checkout will apply it', function () {
    $price = fn (string $code, int $qty) => $this->postJson('http://glow-salon.'.config('wurk.domains.root').'/cart/price', ['lines' => [['variant' => $this->variant, 'quantity' => $qty]], 'coupon' => $code])->json('coupon');
    expect($price('save10', 2))->toBe(['ok' => true, 'discount_cents' => 4000, 'message' => null])
        ->and($price('BIG50', 1))->toBe(['ok' => false, 'discount_cents' => 0, 'message' => 'That code needs a spend of at least R300.00.'])
        ->and($price('NOPE', 1)['message'])->toBe('That code isn’t valid.');
});

it('takes the discount off the goods, counts it towards commission, and keeps to its limits', function () {
    $paid = [];
    Event::listen(PurchaseMade::class, function (PurchaseMade $e) use (&$paid) {
        $paid[] = [$e->paidCents, $e->commissionableCents];
    });
    $order = ($this->order)('082 000 0001');
    expect($order->subtotal_cents)->toBe(40000)->and($order->discount_cents)->toBe(4000)->and($order->total_cents)->toBe(36000)->and($order->coupon_code)->toBe('SAVE10');
    $this->ctx->run($this->glow, fn () => app(OrderManager::class)->advance($order->refresh(), 'paid', 'test'));
    expect($paid)->toBe([[36000, 36000]]);                                                             // commission on what was charged

    expect(fn () => ($this->order)('+27 82 000 0001'))->toThrow(CheckoutProblem::class, 'You’ve already used that code.'); // the same customer
    $second = ($this->order)('082 000 0002');
    expect(fn () => ($this->order)('082 000 0003'))->toThrow(CheckoutProblem::class, 'That code has been used up.');
    $this->ctx->run($this->glow, fn () => app(OrderManager::class)->cancel($second->refresh(), 'test'));   // the order didn't stand: the use comes back
    expect(($this->order)('082 000 0003')->discount_cents)->toBe(4000);

    $this->ctx->run($this->glow, fn () => DB::connection($this->ctx->connectionName())->table('coupons')->where('code', 'BIG50')->update(['ends_at' => now()->subDay()]));
    expect(fn () => ($this->order)('082 000 0009', 2, 'BIG50'))->toThrow(CheckoutProblem::class, 'That code has expired.');
});
