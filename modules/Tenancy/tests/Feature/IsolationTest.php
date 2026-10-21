<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantContextMissing;
use Wurk\Tenancy\Models\Branch;

uses(InteractsWithShards::class);

beforeEach(function () {
    [$this->shardOne, $this->shardTwo] = $this->createShards(2);

    // Two businesses sharing shard one, one business alone on shard two.
    $this->a = $this->createTenant('tenant-a', $this->shardOne);
    $this->b = $this->createTenant('tenant-b', $this->shardOne);
    $this->c = $this->createTenant('tenant-c', $this->shardTwo);

    $this->context = app(TenantContext::class);

    foreach (['a', 'b', 'c'] as $key) {
        $this->context->run($this->{$key}, fn () => Branch::query()->create([
            'name' => strtoupper($key).' Sandton', 'code' => 'sandton', 'timezone' => 'Africa/Johannesburg',
        ]));
    }
});

it('only ever returns the current tenant’s rows', function (string $key) {
    $tenant = $this->{$key};

    $this->context->run($tenant, function () use ($tenant) {
        $rows = Branch::query()->get();

        expect($rows)->toHaveCount(2)
            ->and($rows->pluck('tenant_id')->unique()->all())->toBe([$tenant->id]);
    });
})->with(['a', 'b', 'c']);

it('stores tenants on the same shard together but still isolated', function () {
    $rawShardOne = DB::connection($this->context->run($this->a, fn () => $this->context->connectionName()))
        ->table('branches')->count();

    expect($rawShardOne)->toBe(4); // A and B: default + Sandton each
});

it('throws when tenant data is touched without a tenant context', function () {
    Branch::query()->count();
})->throws(TenantContextMissing::class);

it('throws when creating a row for another tenant', function () {
    $this->context->run($this->a, function () {
        $branch = new Branch(['name' => 'X', 'code' => 'x', 'timezone' => 'UTC']);
        $branch->forceFill(['tenant_id' => $this->b->id])->save();
    });
})->throws(TenantContextMissing::class);

it('throws when updating a row after switching to another tenant', function () {
    $branch = $this->context->run($this->a, fn () => Branch::query()->where('code', 'sandton')->firstOrFail());

    $this->context->run($this->b, fn () => $branch->update(['name' => 'Hijacked']));
})->throws(TenantContextMissing::class);

it('restores the previous context after a nested run', function () {
    $this->context->enter($this->a);

    $this->context->run($this->c, fn () => expect($this->context->id())->toBe($this->c->id));

    expect($this->context->id())->toBe($this->a->id);

    $this->context->forget();
    expect($this->context->has())->toBeFalse();
});

it('namespaces cache keys and storage per tenant', function () {
    $keyA = $this->context->run($this->a, fn () => $this->context->cacheKey('settings'));
    $keyB = $this->context->run($this->b, fn () => $this->context->cacheKey('settings'));

    expect($keyA)->not->toBe($keyB)->toStartWith('t:'.$this->a->id.':');

    $this->context->run($this->a, fn () => $this->context->disk()->put('isolation-probe.txt', 'A'));

    expect($this->context->run($this->a, fn () => $this->context->disk()->exists('isolation-probe.txt')))->toBeTrue()
        ->and($this->context->run($this->b, fn () => $this->context->disk()->exists('isolation-probe.txt')))->toBeFalse()
        ->and($this->context->run($this->a, fn () => $this->context->disk()->path('isolation-probe.txt')))
        ->toContain('tenants'.DIRECTORY_SEPARATOR.$this->a->id);

    $this->context->run($this->a, fn () => $this->context->disk()->delete('isolation-probe.txt'));
});

it('adds the tenant to log context and clears it on leave', function () {
    $this->context->enter($this->a);
    expect(Context::get('tenant_id'))->toBe($this->a->id);

    $this->context->forget();
    expect(Context::has('tenant_id'))->toBeFalse();
});
