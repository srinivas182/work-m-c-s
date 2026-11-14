<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

uses(InteractsWithShards::class);

it('creates load-test businesses across the shards — owners who can sign in, customers, bookings, products — once', function () {
    $this->createShards(2);
    $this->artisan('wurk:seed-load', ['--businesses' => 4, '--customers' => 30, '--bookings' => 20, '--products' => 5])->assertSuccessful();
    $file = json_decode((string) file_get_contents(storage_path('app/load-test.json')), true);
    expect($file['businesses'])->toHaveCount(4)
        ->and(collect($file['businesses'])->pluck('shard')->unique()->count())->toBe(2);   // spread over both shards

    $first = app(TenantDirectory::class)->findByHandle('load-1');
    $ctx = app(TenantContext::class);
    $counts = fn () => $ctx->run($first, fn () => array_map(fn ($t) => DB::connection($ctx->connectionName())->table($t)->where('tenant_id', $first->tenantId())->count(),
        ['contacts' => 'contacts', 'bookings' => 'bookings', 'shop_products' => 'shop_products', 'members' => 'members']));
    expect($counts())->toBe(['contacts' => 30, 'bookings' => 20, 'shop_products' => 5, 'members' => 1]);

    $this->post('http://'.config('wurk.domains.app').'/sign-in/password', ['identifier' => 'owner@load-1.test', 'password' => $file['password']])
        ->assertSessionHasNoErrors();
    $this->assertAuthenticated();

    $this->artisan('wurk:seed-load', ['--businesses' => 4, '--customers' => 30, '--bookings' => 20, '--products' => 5])->assertSuccessful();
    expect($counts())->toBe(['contacts' => 30, 'bookings' => 20, 'shop_products' => 5, 'members' => 1]);  // a second run adds nothing
    @unlink(storage_path('app/load-test.json'));
});

it('refuses in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('wurk:seed-load')->expectsOutputToContain('never created in production')->assertFailed();
});
