<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Shop\Models\Product;
use Wurk\Shop\Models\ShopCategory;
use Wurk\Shop\Models\Variant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Website\Models\Page;
use Wurk\Website\Support\Pages;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'shop', 'marketing'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->staff = $this->person(['phone' => '+27831000002', 'email' => 'staff@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->member($this->staff, $this->glow);
    [$this->thandi, $this->conn] = ($this->in)(function () {
        $m = app(Team::class)->add($this->owner->id, 'Thandi', 'owner');
        app(Team::class)->add($this->staff->id, 'Sam', 'staff');

        return [(string) $m->id, $this->ctx->connectionName()];
    });
    $this->bin = $this->app('/glow-salon/settings/recently-deleted');
    $this->groups = fn ($who = null) => $this->actingAs($who ?? $this->owner)->get($this->bin)->assertOk();
});

it('puts a deleted product back with its options and stock — its address kept meanwhile — and removes it for good after 30 days', function () {
    [$product, $variant] = ($this->in)(function () {
        $p = Product::query()->create(['name' => 'Hair oil', 'slug' => 'hair-oil', 'status' => 'published']);
        $v = Variant::query()->create(['product_id' => $p->id, 'price_cents' => 20000]);
        DB::connection($this->conn)->table('shop_stock')->insert(['variant_id' => $v->id, 'branch_id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'quantity' => 7]);

        return [$p->id, $v->id];
    });
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/shop/products/{$product}"))->assertSessionHas('status', 'Product deleted. You can restore it from Recently deleted for 30 days.');
    expect(($this->in)(fn () => [Product::query()->count(), Variant::query()->count()]))->toBe([0, 0]);
    ($this->groups)()->assertInertia(fn (Assert $p) => $p->component('Data::RecentlyDeleted')->where('groups.0.type', 'products')->where('groups.0.items.0.name', 'Hair oil'));
    ($this->groups)($this->staff)->assertInertia(fn (Assert $p) => $p->where('groups', [])); // staff can't manage the shop
    $this->actingAs($this->staff)->post("{$this->bin}/products/{$product}")->assertNotFound();

    $this->actingAs($this->owner)->post("{$this->bin}/products/{$product}")->assertSessionHas('status', '“Hair oil” is back in your shop, with its options and stock.');
    expect(($this->in)(fn () => [Product::query()->value('slug'), Variant::query()->value('id'), DB::connection($this->conn)->table('shop_stock')->value('quantity')]))->toBe(['hair-oil', $variant, 7]);
    $this->actingAs($this->owner)->post("{$this->bin}/products/{$product}")->assertSessionHas('warning', 'That product isn’t in Recently deleted any more.');

    // Deleted again and left: gone for good after 30 days — variants and stock too.
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/shop/products/{$product}"));
    $this->travel(31)->days();
    $this->artisan('data:purge-deleted')->expectsOutput('Removed 1 for good.');
    expect(($this->in)(fn () => [Product::withTrashed()->count(), Variant::withTrashed()->count(), DB::connection($this->conn)->table('shop_stock')->count()]))->toBe([0, 0, 0]);
});

it('puts a deleted category back with the products that were in it', function () {
    [$cat, $a, $b] = ($this->in)(function () {
        $c = ShopCategory::query()->create(['name' => 'Oils', 'slug' => 'oils', 'position' => 1]);
        $a = Product::query()->create(['name' => 'Argan oil', 'slug' => 'argan', 'status' => 'published', 'category_id' => $c->id]);
        $b = Product::query()->create(['name' => 'Coconut oil', 'slug' => 'coconut', 'status' => 'published', 'category_id' => $c->id]);

        return [$c->id, $a->id, $b->id];
    });
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/shop/categories/{$cat}"));
    expect(($this->in)(fn () => Product::query()->whereNotNull('category_id')->count()))->toBe(0);
    $this->actingAs($this->owner)->post($this->app('/glow-salon/shop/categories'), ['name' => 'Oils'])->assertSessionHasErrors(['name' => 'A recently deleted category has that name — restore it from Recently deleted.']);
    ($this->in)(fn () => Product::query()->whereKey($b)->update(['category_id' => null])); // (still none)

    $this->actingAs($this->owner)->post("{$this->bin}/shop-categories/{$cat}")->assertSessionHas('status', 'Category “Oils” is back, with its products.');
    expect(($this->in)(fn () => Product::query()->where('category_id', $cat)->orderBy('name')->pluck('name')->all()))->toBe(['Argan oil', 'Coconut oil']);
});

it('deletes a service from the booking page while its bookings keep it — and restores it', function () {
    [$cut, $colour] = ($this->in)(function () {
        $cut = BookingService::query()->create(['name' => 'Cut', 'duration_minutes' => 30]);
        $colour = BookingService::query()->create(['name' => 'Colour', 'duration_minutes' => 60]);
        $at = now($this->ctx->current()->timezone())->addDay()->setTime(10, 0);
        Booking::query()->create(['service_id' => $cut->id, 'member_id' => $this->thandi, 'starts_at' => $at, 'ends_at' => $at->copy()->addMinutes(30), 'status' => 'confirmed', 'customer_name' => 'Lerato']);

        return [$cut->id, $colour->id];
    });
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/bookings/services/{$cut}"))
        ->assertSessionHas('status', '“Cut” deleted. Bookings already made for it stay; you can restore it from Recently deleted for 30 days.');
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/bookings/services/{$colour}"));
    $day = ($this->in)(fn () => now($this->ctx->current()->timezone())->addDay()->toDateString());
    $this->actingAs($this->owner)->get($this->app("/glow-salon/bookings?date={$day}"))
        ->assertInertia(fn (Assert $p) => $p->where('services', [])->where('bookings.0.service', 'Cut')); // gone from booking, kept on the booking

    $this->actingAs($this->owner)->post("{$this->bin}/services/{$colour}")->assertSessionHas('status', '“Colour” is back on your booking page.');

    // After 30 days: one with bookings stays (hidden) so they keep its name; one without goes.
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/bookings/services/{$colour}"));
    $this->travel(31)->days();
    $this->artisan('data:purge-deleted');
    expect(($this->in)(fn () => BookingService::withTrashed()->pluck('name')->all()))->toBe(['Cut']);
    expect(($this->in)(fn () => (app(RecentlyDeleted::class)->types()['services']['list'])(now()->subDays(30))))->toBe([]); // no longer listed
});

it('deletes only draft campaigns — never sent, never sent later — and restores them', function () {
    $draft = (string) Str::ulid();
    $sent = (string) Str::ulid();
    ($this->in)(function () use ($draft, $sent) {
        foreach ([[$draft, 'Win back', 'draft'], [$sent, 'Spring sale', 'sent']] as [$id, $name, $status]) {
            DB::connection($this->conn)->table('campaigns')->insert(['id' => $id, 'tenant_id' => $this->glow->id, 'name' => $name, 'channel' => 'email', 'segment' => 'all',
                'subject' => 'Hi', 'body' => 'Hello', 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/campaigns/{$sent}"))->assertSessionHas('warning', 'Only drafts can be deleted — cancel the schedule first.');
    $this->actingAs($this->owner)->delete($this->app("/glow-salon/campaigns/{$draft}"))->assertRedirect()->assertSessionHas('status', 'Draft “Win back” deleted. You can restore it from Recently deleted for 30 days.');
    $this->actingAs($this->owner)->get($this->app("/glow-salon/campaigns/{$draft}"))->assertNotFound();
    $this->actingAs($this->owner)->post($this->app("/glow-salon/campaigns/{$draft}/send"), ['when' => 'now'])->assertNotFound();
    $this->actingAs($this->owner)->get($this->app('/glow-salon/campaigns'))->assertInertia(fn (Assert $p) => $p->has('campaigns', 1));

    $this->actingAs($this->owner)->post("{$this->bin}/campaigns/{$draft}")->assertSessionHas('status', 'Draft “Win back” is back in Campaigns.');
    $this->actingAs($this->owner)->get($this->app('/glow-salon/campaigns'))->assertInertia(fn (Assert $p) => $p->has('campaigns', 2));
});

it('brings pages back from the same place, and removes them for good — with their versions — after 30 days', function () {
    $id = ($this->in)(function () {
        $page = app(Pages::class)->create('About us', null, $this->owner->id);
        app(Pages::class)->delete($page);

        return $page->id;
    });
    ($this->groups)()->assertInertia(fn (Assert $p) => $p->where('groups.0.type', 'pages')->where('groups.0.items.0.name', 'About us'));
    $this->actingAs($this->owner)->post("{$this->bin}/pages/{$id}")->assertSessionHas('status', 'Page “About us” restored.');
    $this->actingAs($this->owner)->post("{$this->bin}/unknown-type/x")->assertNotFound();
    ($this->in)(fn () => app(Pages::class)->delete(Page::query()->findOrFail($id)));
    $this->travel(31)->days();
    $this->artisan('data:purge-deleted')->expectsOutput('Removed 1 for good.');
    expect(($this->in)(fn () => Page::withTrashed()->whereKey($id)->exists()))->toBeFalse();
});
