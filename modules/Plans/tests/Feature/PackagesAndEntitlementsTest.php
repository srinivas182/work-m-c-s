<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Actions\Subscriptions;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function packageData(array $overrides = []): array
{
    return [
        'key' => 'starter', 'name' => 'Starter', 'description' => 'For new businesses', 'type' => 'self_managed',
        'visibility' => 'public', 'display_order' => 10, 'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000,
        'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 3, 'multi_branch' => false, 'max_branches' => 1,
        'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7, 'referral_max_commission_bp' => 3000,
        'managed_fee_cents' => null, 'modules' => ['website', 'bookings'], ...$overrides,
    ];
}

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->catalog = app(PackageCatalog::class);
    $this->subs = app(Subscriptions::class);
});

it('versions commercial changes and keeps existing businesses on their terms', function () {
    $package = $this->catalog->create(packageData(), null);
    $this->subs->assign($this->glow->id, $package);
    $v1 = $package->current_version_id;

    $this->catalog->update($package, packageData(['name' => 'Starter Plus', 'description' => 'Renamed']), null);
    expect($package->fresh()->current_version_id)->toBe($v1)->and($package->fresh()->name)->toBe('Starter Plus');

    $this->catalog->update($package->fresh(), packageData(['monthly_price_cents' => 34900]), null);
    $package->refresh();
    expect($package->currentVersion->version)->toBe(2)
        ->and(TenantSubscription::query()->find($this->glow->id)->package_version_id)->toBe($v1);

    $spa = $this->createTenant('glow-spa');
    $this->subs->assign($spa->id, $package);
    expect(TenantSubscription::query()->find($spa->id)->version->monthly_price_cents)->toBe(34900)
        ->and(PlatformAuditEvent::query()->where('action', 'package.version_created')->exists())->toBeTrue();
});

it('never lets a sold version change', function () {
    $version = $this->catalog->create(packageData(), null)->currentVersion;
    $version->update(['monthly_price_cents' => 1]);
})->throws(LogicException::class, 'immutable');

it('rejects unknown modules and archived packages', function () {
    expect(fn () => $this->catalog->create(packageData(['key' => 'odd', 'modules' => ['teleport']]), null))->toThrow(ValidationException::class);

    $package = $this->catalog->create(packageData(), null);
    $this->catalog->archive($package, true);
    expect(fn () => $this->subs->assign($this->glow->id, $package->fresh()))->toThrow(ValidationException::class);
});

it('starts a trial only for a business’s first package', function () {
    $package = $this->catalog->create(packageData(), null);
    $this->subs->assign($this->glow->id, $package);
    $sub = TenantSubscription::query()->find($this->glow->id);
    expect($sub->status)->toBe('trial')->and($sub->trial_ends_at->diffInDays(now()->addDays(14), true))->toBeLessThan(1);

    $this->subs->assign($this->glow->id, $package);
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('active');
});

it('combines package modules with per-business exceptions', function () {
    $this->subs->assign($this->glow->id, $this->catalog->create(packageData(), null));
    $entitlements = app(Entitlements::class);

    expect($entitlements->modules($this->glow->id))->toEqualCanonicalizing(['website', 'bookings'])
        ->and($entitlements->limits($this->glow->id)['staff_limit'])->toBe(3);

    $this->subs->override($this->glow->id, 'shop', true, 'Pilot business', null);
    $this->subs->override($this->glow->id, 'bookings', false, 'Disputed account', null);
    expect($entitlements->modules($this->glow->id))->toEqualCanonicalizing(['website', 'shop']);

    $this->subs->override($this->glow->id, 'bookings', null, '', null);
    expect($entitlements->entitled($this->glow->id, 'bookings'))->toBeTrue()
        ->and(PlatformAuditEvent::query()->where('action', 'entitlement.overridden')->count())->toBe(3);
});

it('shows a friendly page instead of a module the business does not have, and hides its menu item', function () {
    Route::tenant(function () {
        Route::get('/bookings-probe', fn () => 'bookings')->middleware('entitled:bookings')->name('probe.bookings');
    }, requiresAuth: false);
    Route::getRoutes()->refreshNameLookups();

    $this->get($this->app('/glow-salon/bookings-probe'))->assertStatus(403)
        ->assertInertia(fn (Assert $p) => $p->component('Plans::NotIncluded')->where('module', 'Appointments'));

    $this->subs->assign($this->glow->id, $this->catalog->create(packageData(), null));
    $this->get($this->app('/glow-salon/bookings-probe'))->assertOk()->assertSee('bookings');

    expect(app(TenantContext::class)->run($this->glow, fn () => Gate::allows('use-module', 'bookings')))->toBeTrue()
        ->and(app(TenantContext::class)->run($this->glow, fn () => Gate::allows('use-module', 'shop')))->toBeFalse();
});

it('lets finance see packages but only package managers change them', function () {
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $this->actingAs($finance)->get($this->admin('/packages'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('canManage', false));
    $this->actingAs($finance)->get($this->admin('/packages/new'))->assertForbidden();

    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->post($this->admin('/packages'), [
        'key' => 'growth', 'name' => 'Growth', 'description' => '', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 20,
        'monthly_price' => '599.50', 'yearly_price' => '5995', 'trial_days' => 14, 'storage_mb' => 5120, 'staff_limit' => 10,
        'multi_branch' => true, 'max_branches' => 3, 'custom_domain' => true, 'monthly_credits' => 500,
        'referral_max_levels' => 7, 'referral_max_commission' => 25.5, 'modules' => ['website', 'shop'],
    ])->assertRedirect();

    $version = Package::query()->where('key', 'growth')->firstOrFail()->currentVersion;
    expect($version->monthly_price_cents)->toBe(59950)->and($version->referral_max_commission_bp)->toBe(2550);
});

it('lets package managers change a business’s plan and modules from the admin', function () {
    $package = $this->catalog->create(packageData(), null);
    $admin = $this->staffWithRole('super-admin');

    $this->actingAs($admin)->put($this->admin("/businesses/{$this->glow->id}/plan"), ['package_id' => $package->id])->assertSessionHas('status');
    $this->actingAs($admin)->post($this->admin("/businesses/{$this->glow->id}/plan/modules"), ['module' => 'shop', 'state' => 'grant', 'reason' => 'Pilot of the shop'])->assertSessionHas('status');
    $this->actingAs($admin)->post($this->admin("/businesses/{$this->glow->id}/plan/modules"), ['module' => 'crm', 'state' => 'grant', 'reason' => ''])->assertSessionHasErrors('reason');

    $this->actingAs($admin)->get($this->admin("/businesses/{$this->glow->id}/plan"))
        ->assertInertia(fn (Assert $p) => $p->component('Plans::Admin/BusinessPlan')->where('subscription.package', 'Starter')
            ->where('modules', fn ($m) => collect($m)->firstWhere('key', 'shop')['enabled'] === true));
});
