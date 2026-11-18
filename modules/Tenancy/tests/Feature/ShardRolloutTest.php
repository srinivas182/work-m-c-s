<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Actions\ShardHealth;
use Wurk\Tenancy\Sharding\ShardConnections;

uses(InteractsWithShards::class);

/** Sprint 18.4b: migrations reach the canary shard first, are health-checked, and a failure is rolled back and stops the rollout. */
beforeEach(function () {
    [$this->one, $this->two] = $this->createShards(2);
    $this->conn = fn ($shard) => app(ShardConnections::class)->register($shard);
    // A release with two new tenant migrations (in a folder of the test's own, so later real migrations never change
    // what's tested): one adds a column to contacts, the next one to bookings.
    $this->dir = sys_get_temp_dir().'/wurk-rollout-'.bin2hex(random_bytes(4));
    mkdir($this->dir);
    foreach (['2999_01_01_000001_probe_contacts' => 'contacts', '2999_01_01_000002_probe_bookings' => 'bookings'] as $file => $table) {
        file_put_contents("{$this->dir}/{$file}.php", "<?php\nuse Illuminate\\Database\\Migrations\\Migration;\nuse Illuminate\\Database\\Schema\\Blueprint;\nuse Illuminate\\Support\\Facades\\Schema;\n"
            ."return new class extends Migration {\n    public function up(): void { Schema::table('{$table}', fn (Blueprint \$t) => \$t->string('rollout_probe')->nullable()); }\n"
            ."    public function down(): void { Schema::table('{$table}', fn (Blueprint \$t) => \$t->dropColumn('rollout_probe')); }\n};\n");
    }
    config(['wurk.tenancy.extra_migration_paths' => [$this->dir]]);
    $this->has = fn ($shard) => Schema::connection(($this->conn)($shard))->hasColumn('contacts', 'rollout_probe');
});

afterEach(function () {
    array_map('unlink', glob($this->dir.'/*.php') ?: []);
    rmdir($this->dir);
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
    Schema::connection(($this->conn)($this->one))->table('bookings', fn ($t) => $t->string('rollout_probe')->nullable());

    $this->artisan('shards:migrate')->expectsOutputToContain('were rolled back')->expectsOutputToContain('Rollout stopped')->assertFailed();
    expect(($this->has)($this->one))->toBeFalse()    // the contacts migration it applied first was undone
        ->and(($this->has)($this->two))->toBeFalse(); // and shard two was never touched
});
