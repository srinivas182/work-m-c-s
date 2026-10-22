<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Features\Support\FeatureFlags;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    app()->register(new class(app()) extends ModuleServiceProvider
    {
        public function features(): array
        {
            return ['new-calendar' => ['label' => 'New booking calendar', 'description' => 'Drag-and-drop week view']];
        }
    });
    $this->flags = app(FeatureFlags::class);
    $this->flags->flush();
    Feature::define('new-calendar', fn (?string $scope) => $this->flags->evaluate('new-calendar', $scope));

    $this->createShards(1);
    $this->a = $this->createTenant('tenant-a');
    $this->b = $this->createTenant('tenant-b');
    $this->context = app(TenantContext::class);
});

function activeFor($test, $tenant): bool
{
    return $test->context->run($tenant, fn () => Feature::active('new-calendar'));
}

it('is off until rolled out, and the kill switch beats everything', function () {
    expect(activeFor($this, $this->a))->toBeFalse();

    $this->flags->setRule('new-calendar', FeatureFlags::ON, 0, null);
    expect(activeFor($this, $this->a))->toBeTrue();

    $this->flags->setOptIn($this->a->id, 'new-calendar', true, null);
    $this->flags->setRule('new-calendar', FeatureFlags::OFF, 0, null);
    expect(activeFor($this, $this->a))->toBeFalse();
});

it('lets businesses opt in, then becomes standard for everyone after the window', function () {
    $this->flags->setRule('new-calendar', FeatureFlags::OPT_IN, 0, null);
    expect(activeFor($this, $this->a))->toBeFalse();

    $this->flags->setOptIn($this->a->id, 'new-calendar', true, null);
    expect(activeFor($this, $this->a))->toBeTrue()->and(activeFor($this, $this->b))->toBeFalse();

    $this->travel(FeatureFlags::OPT_IN_WINDOW_DAYS + 1)->days();
    Feature::purge('new-calendar');
    expect(activeFor($this, $this->b))->toBeTrue();
});

it('rolls out to a stable percentage of businesses', function () {
    $this->flags->setRule('new-calendar', FeatureFlags::PERCENTAGE, 50, null);
    $ids = collect(range(1, 400))->map(fn ($i) => "tenant:{$i}");
    $on = $ids->filter(fn ($scope) => Feature::for($scope)->active('new-calendar'))->count();

    expect($on)->toBeGreaterThan(160)->toBeLessThan(240)
        ->and($ids->filter(fn ($scope) => Feature::for($scope)->active('new-calendar'))->count())->toBe($on);
});

it('applies a rule change even after values were cached', function () {
    expect(activeFor($this, $this->a))->toBeFalse(); // cached by Pennant
    $this->flags->setRule('new-calendar', FeatureFlags::ON, 0, null);
    expect(activeFor($this, $this->a))->toBeTrue();
});

it('lets owners switch optional features on from their settings, and only them', function () {
    $this->flags->setRule('new-calendar', FeatureFlags::OPT_IN, 0, null);
    $owner = $this->person();
    $desk = $this->person(['email' => 'desk@x.co.za', 'phone' => null]);
    foreach ([[$owner, 'owner'], [$desk, 'staff']] as [$who, $role]) {
        $this->member($who, $this->a);
        $this->context->run($this->a, fn () => app(Team::class)->add($who->id, 'X', $role));
    }

    $this->actingAs($owner)->get($this->app('/tenant-a/settings/features'))
        ->assertInertia(fn (Assert $p) => $p->component('Features::Business')->where('features.0.key', 'new-calendar')->where('features.0.on', false));
    $this->actingAs($owner)->put($this->app('/tenant-a/settings/features/new-calendar'), ['on' => true])->assertSessionHas('status');
    expect(activeFor($this, $this->a))->toBeTrue();

    $this->actingAs($desk)->get($this->app('/tenant-a/settings/features'))->assertNotFound();
});

it('lets only feature managers change rollouts', function () {
    $this->actingAs($this->staffWithRole('support'))->put($this->admin('/features/new-calendar'), ['mode' => 'on'])->assertForbidden();

    $admin = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->actingAs($admin)->get($this->admin('/features'))->assertInertia(fn (Assert $p) => $p->where('features.0.key', 'new-calendar'));
    $this->actingAs($admin)->put($this->admin('/features/new-calendar'), ['mode' => 'percentage', 'percentage' => 10])->assertSessionHas('status');
    expect($this->flags->rule('new-calendar'))->toMatchArray(['mode' => 'percentage', 'percentage' => 10]);
    $this->actingAs($admin)->put($this->admin('/features/unknown-thing'), ['mode' => 'on'])->assertNotFound();
});
