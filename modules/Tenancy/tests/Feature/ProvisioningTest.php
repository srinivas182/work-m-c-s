<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Models\Branch;
use Wurk\Tenancy\Models\Shard;
use Wurk\Tenancy\Models\Tenant;

uses(InteractsWithShards::class);

it('provisions a business with a subdomain and a default branch on its shard', function () {
    [$shard] = $this->createShards(1);

    $tenant = $this->createTenant('glow-salon');

    expect($tenant->status)->toBe(Tenant::STATUS_ACTIVE)
        ->and($tenant->shard_id)->toBe($shard->id)
        ->and($tenant->domains()->value('host'))->toBe('glow-salon.'.config('wurk.domains.root'))
        ->and($shard->fresh()->tenant_count)->toBe(1);

    app(TenantContext::class)->run($tenant, function () {
        expect(Branch::query()->pluck('code')->all())->toBe(['main']);
    });
});

it('places new businesses on the least-loaded shard', function () {
    [$a, $b] = $this->createShards(2);

    $first = $this->createTenant('first-biz');
    $second = $this->createTenant('second-biz');

    expect([$first->shard_id, $second->shard_id])->toEqualCanonicalizing([$a->id, $b->id]);
});

it('refuses invalid, reserved and duplicate handles', function (string $handle) {
    $this->createShards(1);
    $this->createTenant('taken-name');

    $this->createTenant($handle);
})->with(['ab', '-bad', 'bad-', 'has space', 'double--hyphen', 'admin', 'taken-name'])
    ->throws(ValidationException::class);

it('fails clearly when no shard has capacity', function () {
    $this->createShards(1, capacity: 1);
    $this->createTenant('only-one');

    $this->createTenant('one-too-many');
})->throws(RuntimeException::class, 'No shard has capacity');

it('records the schema version on each shard', function () {
    [$shard] = $this->createShards(1);

    expect($shard->fresh()->schema_version)->toBeGreaterThan(0)
        ->and($shard->fresh()->migrated_at)->not->toBeNull();
});

it('stops the shard rollout at the first failing shard', function () {
    [$good] = $this->createShards(1);
    $broken = Shard::query()->create([
        'name' => 'a-broken-shard',
        'connection' => ['driver' => 'sqlite', 'database' => '/nonexistent/dir/shard.sqlite'],
        'status' => Shard::STATUS_ACTIVE,
        'capacity' => 10,
    ]);

    $this->artisan('shards:migrate')
        ->expectsOutputToContain('Rollout stopped')
        ->assertFailed();

    expect($broken->fresh()->migrated_at)->toBeNull();
});

it('provisions through the tenants:create command', function () {
    $this->createShards(1);

    $this->artisan('tenants:create', ['name' => 'Bright Smiles Dental'])
        ->expectsOutputToContain('bright-smiles-dental')
        ->assertSuccessful();

    expect(Tenant::query()->where('handle', 'bright-smiles-dental')->exists())->toBeTrue();
});
