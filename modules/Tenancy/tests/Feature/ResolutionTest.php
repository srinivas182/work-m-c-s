<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Branch;
use Wurk\Tenancy\Models\Tenant;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(2);
    $this->a = $this->createTenant('tenant-a');
    $this->b = $this->createTenant('tenant-b');

    // Probe routes: report which tenant the request ran in and what it can see.
    Route::tenant(function () {
        Route::get('/probe', fn (TenantContext $context) => [
            'tenant' => $context->current()->handle(),
            'branches' => Branch::query()->count(),
            'url' => route('probe.self'),
        ])->name('probe.self');
    });
    Route::tenantSite(function () {
        Route::get('/site-probe', fn (TenantContext $context) => ['tenant' => $context->current()->handle()]);
    });
    Route::getRoutes()->refreshNameLookups();
});

function appUrl(string $path): string
{
    return 'http://'.config('wurk.domains.app').$path;
}

it('resolves the business from the back-office path', function () {
    $this->getJson(appUrl('/tenant-a/probe'))
        ->assertOk()
        ->assertJson(['tenant' => 'tenant-a', 'branches' => 1, 'url' => appUrl('/tenant-a/probe')]);
});

it('isolates interleaved requests on one long-lived application instance', function () {
    foreach (['tenant-a', 'tenant-b', 'tenant-a', 'tenant-b'] as $handle) {
        $this->getJson(appUrl("/{$handle}/probe"))->assertJsonPath('tenant', $handle);
        expect(app(TenantContext::class)->has())->toBeFalse();
    }
});

it('returns 404 for unknown and inactive businesses', function () {
    $this->getJson(appUrl('/nobody-here/probe'))->assertNotFound();

    $this->a->update(['status' => Tenant::STATUS_SUSPENDED]);
    $this->getJson(appUrl('/tenant-a/probe'))->assertNotFound();
});

it('follows a handle change immediately', function () {
    $this->getJson(appUrl('/tenant-a/probe'))->assertOk(); // warm the cache
    $this->a->update(['handle' => 'tenant-a-renamed']);

    $this->getJson(appUrl('/tenant-a/probe'))->assertNotFound();
    $this->getJson(appUrl('/tenant-a-renamed/probe'))->assertJsonPath('tenant', 'tenant-a-renamed');
});

it('resolves public sites by host, including custom domains', function () {
    $this->getJson('http://tenant-b.'.config('wurk.domains.root').'/site-probe')->assertJsonPath('tenant', 'tenant-b');

    $this->a->domains()->create(['host' => 'glowsalon.co.za', 'type' => 'custom', 'verified_at' => now()]);
    $this->getJson('http://glowsalon.co.za/site-probe')->assertJsonPath('tenant', 'tenant-a');

    $this->getJson('http://unknown.example.com/site-probe')->assertNotFound();
});
