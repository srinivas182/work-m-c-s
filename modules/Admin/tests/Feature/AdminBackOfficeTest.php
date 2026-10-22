<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\AccessManager;
use Wurk\Access\Actions\Team;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Identity\Models\IdentityMfa;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
});

it('shows staff a dashboard of businesses, capacity and recent activity', function () {
    $this->actingAs($this->staffWithRole('super-admin'))->get($this->admin('/dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Admin::Dashboard')
            ->where('businesses.active', 1)
            ->where('staff', 1)
            ->where('recent', fn ($r) => collect($r)->contains('action', 'tenant.created')));
});

it('keeps everyone except active staff with an authenticator out of the admin', function () {
    $this->actingAs($this->person())->get($this->admin('/dashboard'))->assertForbidden();

    $staff = $this->staffWithRole('super-admin');
    IdentityMfa::query()->whereKey($staff->id)->delete();
    $this->actingAs($staff)->get($this->admin('/dashboard'))->assertForbidden();
});

it('removes every platform permission the moment staff are deactivated', function () {
    $admin = $this->staffWithRole('super-admin');
    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    expect($support->can('tenants.view'))->toBeTrue();

    app(PeopleDirectory::class)->deactivateStaff($support->id);
    app(AccessManager::class)->flush();

    expect($support->fresh()->can('tenants.view'))->toBeFalse();
    $this->actingAs($support->fresh())->get($this->admin('/dashboard'))->assertForbidden();
    $this->actingAs($admin)->get($this->admin('/dashboard'))->assertOk();
});

it('shows each back office only its own navigation', function () {
    $this->actingAs($this->staffWithRole('super-admin'))->get($this->admin('/dashboard'))
        ->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => collect($n)->pluck('label')->intersect(['Dashboard', 'Businesses', 'WURK staff', 'Staff roles', 'Audit log'])->count() === 5
            && collect($n)->every(fn ($i) => ! str_starts_with($i['href'], '/glow-salon'))));

    $owner = $this->person(['email' => 'owner@glow.co.za', 'phone' => null]);
    $this->member($owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($owner->id, 'Owner', 'owner'));

    $this->actingAs($owner)->get($this->app('/glow-salon'))
        ->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => ! collect($n)->pluck('label')->contains('Businesses') && ! collect($n)->pluck('label')->contains('Dashboard')));
});
