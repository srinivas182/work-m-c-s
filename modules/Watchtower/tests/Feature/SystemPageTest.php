<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Actions\RegisterShard;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Watchtower\Models\Incident;
use Wurk\Watchtower\Support\SystemChecks;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** Sprint 18.4c: Admin → System — migrations per database, backups (and overdue ones), restore drills, business moves. */
beforeEach(function () {
    Mail::fake();
    config(['wurk.watchtower.recipients' => ['hq@example.test'], 'wurk.watchtower.ai' => false]);
    [$this->one, $this->two] = $this->createShards(2);
    $this->glow = $this->createTenant('glow-salon', $this->one, 'Glow Salon');
    $this->engineer = $this->staffWithRole('engineering', 'eng@wurk.co.za');
    $this->page = $this->admin('/system');
});

it('records backups and drills, and shows HQ the state of every database, backup, drill and move', function () {
    $this->artisan('system:backup-taken', ['target' => 'central', '--kind' => 'snapshot', '--size' => 5_242_880, '--location' => 'rds:wurk-central-2026-11-14'])->assertSuccessful();
    $this->artisan('system:backup-taken', ['target' => 'shard-1', '--kind' => 'weekly'])->expectsOutputToContain('Kind must be one of')->assertFailed();
    $this->artisan('system:drill-recorded', ['--scope' => 'shard', '--target' => $this->one->name, '--failed' => '2026-11-14 10:00', '--restored' => '2026-11-14 10:42',
        '--backup' => '2026-11-14 09:55', '--note' => 'Local drill'])->expectsOutputToContain('Passed — back in 42 min')->assertSuccessful();
    $this->artisan('system:drill-recorded', ['--failed' => '2026-11-14 10:00', '--restored' => '2026-11-14 15:00', '--backup' => '2026-11-14 08:00'])
        ->expectsOutputToContain('Missed the targets')->assertFailed();
    $this->artisan('tenants:move', ['handle' => 'glow-salon', '--to' => $this->two->name, '--force' => true])->assertSuccessful();

    $this->actingAs($this->engineer)->get($this->page)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Watchtower::System')
        ->where('central.pending', [])
        ->has('shards', 2)->where('shards.0.name', $this->one->name)->where('shards.0.schema_version', fn ($v) => $v > 0 && $v === $this->one->fresh()->schema_version)
        ->where('shards.1.businesses', 1)
        ->where('backups.0.target', 'central')->where('backups.0.size_bytes', 5_242_880)->where('backups.0.late', false)
        ->has('drills', 2)->where('drills.0.restore_minutes', 300)->where('drills.0.passed', false)->where('drills.1.data_loss_minutes', 5)->where('drills.1.passed', true)
        ->where('moves.0.business', 'Glow Salon')->where('moves.0.to', $this->two->name)->where('moves.0.status', 'done')->where('moves.0.rows', fn ($n) => $n > 0));

    $this->actingAs($this->staffWithRole('support', 'help@wurk.co.za'))->get($this->page)->assertOk();
    $this->actingAs($this->staffWithRole('finance', 'money@wurk.co.za'))->get($this->page)->assertForbidden();
});

it('raises a problem when an expected backup is overdue, and clears it when one arrives', function () {
    config(['wurk.watchtower.backup_targets' => ['central', 'files']]);
    $this->artisan('system:backup-taken', ['target' => 'central', '--at' => now()->subHours(30)->toDateTimeString()])->assertSuccessful();
    app(SystemChecks::class)->run();
    $problem = Incident::query()->where('fingerprint', sha1('check|backups'))->sole();
    expect($problem->severity)->toBe('high')->and($problem->title)->toContain('central (30 hours ago)')->toContain('files (never)');
    $this->actingAs($this->engineer)->get($this->page)->assertInertia(fn (Assert $p) => $p->where('backups.0.late', true)->where('backups.1.target', 'files')->where('backups.1.taken_at', null));

    $this->artisan('system:backup-taken', ['target' => 'central'])->assertSuccessful();
    $this->artisan('system:backup-taken', ['target' => 'files', '--kind' => 'files'])->assertSuccessful();
    app(SystemChecks::class)->run();
    expect($problem->fresh()->status)->toBe('resolved');
});

it('points a shard at a restored copy of its database — only when the copy is healthy', function () {
    // A healthy, migrated database standing in for the shard's restored backup.
    $target = env('TEST_SHARD_DRIVER', 'sqlite') === 'mysql'
        ? ['driver' => 'mysql', 'database' => explode(',', (string) env('TEST_SHARD_DATABASES'))[2] ?? 'wurk_shard_3']
        : ['driver' => 'sqlite', 'database' => $this->temporaryShardFile(9)];
    app(RegisterShard::class)('restored-copy', $target + ($target['driver'] === 'mysql' ? ['host' => env('DB_HOST'), 'port' => env('DB_PORT'), 'username' => env('DB_USERNAME'), 'password' => env('DB_PASSWORD')] : ['foreign_key_constraints' => true]), null, fresh: true);
    $this->artisan('shards:repoint', ['name' => $this->one->name, '--driver' => 'sqlite', '--database' => '/nonexistent/x.sqlite', '--force' => true])
        ->expectsOutputToContain('Not switched')->assertFailed();
    $this->artisan('shards:repoint', ['name' => $this->one->name, '--driver' => $target['driver'], '--database' => $target['database'], '--force' => true])
        ->expectsOutputToContain('now uses the new database')->assertSuccessful();
    expect($this->one->fresh()->connection['database'])->toBe($target['database'])
        ->and(Tenant::query()->find($this->glow->id)->shard_id)->toBe($this->one->id);
});
