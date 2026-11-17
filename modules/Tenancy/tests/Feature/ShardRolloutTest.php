<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Actions\ShardHealth;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantMigrationPaths;

uses(InteractsWithShards::class);

/** Sprint 18.4b: migrations reach the canary shard first, are health-checked, and a failure is rolled back and stops the rollout. */
beforeEach(function () {
    [$this->one, $this->two] = $this->createShards(2);
    $this->conn = fn ($shard) => app(ShardConnections::class)->register($shard);
    // The last three tenant migrations become pending on both shards, as after a deploy.
    foreach ([$this->one, $this->two] as $shard) {
        Artisan::call('migrate:rollback', ['--database' => ($this->conn)($shard), '--step' => 3, '--force' => true,
            '--path' => app(TenantMigrationPaths::class)->all(), '--realpath' => true]);
    }
    $this->has = fn ($shard) => Schema::connection(($this->conn)($shard))->hasColumn('contacts', 'erased_at');
});

it('migrates the canary first (the chosen one, or the first by name), health-checks every shard, and can stop after the canary', function () {
    config(['wurk.tenancy.canary_shard' => $this->two->name]);
    expect(app(ShardHealth::class)->problem($this->two))->toContain('migration(s) not applied');

    $this->artisan('shards:migrate', ['--canary' => true])->expectsOutputToContain('Canary migrated and healthy')->assertSuccessful();
    expect(($this->has)($this->two))->toBeTrue()->and(($this->has)($this->one))->toBeFalse()
        ->and(app(ShardHealth::class)->problem($this->two))->toBeNull();

    $this->artisan('shards:migrate')->expectsOutputToContain($this->two->name.' (canary)')->assertSuccessful();
    expect(($this->has)($this->one))->toBeTrue()
        ->and($this->one->fresh()->schema_version)->toBe(count(app(ShardHealth::class)->expected()));
});

it('rolls back what it applied to a shard when a migration fails, and goes no further', function () {
    // Shard one (the canary) can't take the bookings migration: the column is already there.
    Schema::connection(($this->conn)($this->one))->table('bookings', fn ($t) => $t->dateTime('cancelled_at')->nullable());

    $this->artisan('shards:migrate')->expectsOutputToContain('were rolled back')->expectsOutputToContain('Rollout stopped')->assertFailed();
    expect(($this->has)($this->one))->toBeFalse()    // the contacts migration it applied first was undone
        ->and(($this->has)($this->two))->toBeFalse(); // and shard two was never touched
});
