<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Website\Models\Page;
use Wurk\Website\Models\SiteLaunch;
use Wurk\Website\Support\Pages;
use Wurk\Website\Support\SiteBuilder;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->artisan('website:install-templates');
    $this->createShards(1);
    $this->ctx = app(TenantContext::class);
    $this->launchAfter = function (string $handle, int $minutes): void {
        $tenant = $this->createTenant($handle);
        $this->ctx->run($tenant, fn () => app(SiteBuilder::class)->apply('salon-hair-braids'));
        $this->travel($minutes)->minutes();
        $this->ctx->run($tenant, function () {
            $pages = app(Pages::class);
            $before = SiteLaunch::query()->count();
            $pages->publish(Page::query()->where('slug', 'services')->firstOrFail(), null, null); // not the home page: not a launch
            expect(SiteLaunch::query()->count())->toBe($before);
            $pages->publish(Page::query()->where('is_home', true)->firstOrFail(), 'Launch', null);
        });
        $this->travelBack();
    };
});

it('records time to launch once — at the first publish of the home page', function () {
    ($this->launchAfter)('glow-salon', 42);
    $launch = SiteLaunch::query()->firstOrFail();
    expect($launch->only(['template_key', 'minutes']))->toBe(['template_key' => 'salon-hair-braids', 'minutes' => 42]);

    $glow = Tenant::query()->where('handle', 'glow-salon')->firstOrFail();
    $this->travel(3)->days();
    $this->ctx->run($glow, fn () => app(Pages::class)->publish(Page::query()->where('is_home', true)->firstOrFail(), 'Update', null));
    expect(SiteLaunch::query()->firstOrFail()->minutes)->toBe(42); // the first launch counts
});

it('shows staff the median and the share launched within an hour', function () {
    ($this->launchAfter)('quick-biz', 30);
    ($this->launchAfter)('slow-biz', 90);

    $staff = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->actingAs($staff)->get($this->admin('/launch-times'))->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Website::Admin/LaunchTimes')->where('median', 60)->where('withinHour', 50)->where('count', 2));
});
