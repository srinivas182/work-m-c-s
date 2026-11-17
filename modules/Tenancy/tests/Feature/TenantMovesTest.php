<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Tests\Support\SeedsEveryTable;
use Wurk\Access\Actions\Team;
use Wurk\Tenancy\Actions\TenantMoves;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantProvisioner;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Models\TenantMove;
use Wurk\Tenancy\Sharding\ShardConnections;
use Wurk\Tenancy\Support\TenantRows;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('local');
    [$this->one, $this->two, $this->backupShard] = $this->createShards(3);
    $this->glow = $this->createTenant('glow-salon', $this->one, 'Glow Salon');
    $this->other = $this->createTenant('bravo-bakery', $this->one, 'Bravo Bakery');   // a neighbour on the same shard
    $this->conn = fn ($shard) => app(ShardConnections::class)->register($shard);
    $this->owner = $this->person(['email' => 'owner@glow.co.za', 'phone' => '+27831000000']);
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    (new SeedsEveryTable(($this->conn)($this->one), $this->glow->id))->run();       // a row in every table
    (new SeedsEveryTable(($this->conn)($this->one), $this->other->id))->run();
    $this->rows = app(TenantRows::class);
    $this->page = 'http://'.config('wurk.domains.app').'/glow-salon/contacts';
});

it('moves a business to another shard — identical copies, the neighbour untouched, the old copy kept 7 days then removed', function () {
    $before = $this->rows->checksum($this->glow->id, ($this->conn)($this->one));
    $neighbour = $this->rows->checksum($this->other->id, ($this->conn)($this->one));
    $this->artisan('tenants:move', ['handle' => 'glow-salon', '--to' => $this->two->name, '--force' => true])->expectsOutputToContain('copies identical')->assertSuccessful();

    expect(Tenant::query()->find($this->glow->id)->shard_id)->toBe($this->two->id)
        ->and([$this->one->fresh()->tenant_count, $this->two->fresh()->tenant_count])->toBe([1, 1])  // capacity follows the business
        ->and($this->rows->checksum($this->glow->id, ($this->conn)($this->two)))->toBe($before)
        ->and(count($before))->toBeGreaterThan(80)
        ->and($this->rows->checksum($this->other->id, ($this->conn)($this->one)))->toBe($neighbour);
    // The business works from its new shard.
    $this->actingAs($this->owner)->get($this->page)->assertOk();
    expect(app(TenantContext::class)->run(Tenant::query()->find($this->glow->id), fn () => app(TenantContext::class)->connectionName()))->toBe(($this->conn)($this->two));
    expect(TenantMove::query()->sole())->status->toBe('done')->kind->toBe('move');

    $this->travel(TenantMoves::KEEP_DAYS + 1)->days();
    $this->artisan('tenants:clear-moved')->expectsOutput('Removed 1 old copies.');
    expect($this->rows->counts($this->glow->id, ($this->conn)($this->one)))->toBe([])
        ->and($this->rows->checksum($this->other->id, ($this->conn)($this->one)))->toBe($neighbour)  // never the neighbour
        ->and($this->rows->checksum($this->glow->id, ($this->conn)($this->two)))->toBe($before);    // never the copy in use
});

it('pauses the business’s pages while it moves, and leaves no half copy if the move fails', function () {
    Cache::put('tenancy:paused:'.$this->glow->id, true, 60);
    $this->actingAs($this->owner)->get($this->page)->assertStatus(503)->assertSee('being moved to a new server');
    Cache::forget('tenancy:paused:'.$this->glow->id);

    Schema::connection(($this->conn)($this->two))->drop('contact_links');               // the destination isn't migrated
    $this->artisan('tenants:move', ['handle' => 'glow-salon', '--to' => $this->two->name, '--force' => true])
        ->expectsOutputToContain('Not moved: The destination has no table contact_links')->assertFailed();
    expect(Tenant::query()->find($this->glow->id)->shard_id)->toBe($this->one->id)
        ->and($this->rows->counts($this->glow->id, ($this->conn)($this->two)))->toBe([])
        ->and(TenantMoves::paused($this->glow->id))->toBeFalse()
        ->and(TenantMove::query()->sole()->status)->toBe('failed');
    $this->actingAs($this->owner)->get($this->page)->assertOk();
});

it('restores one business from a backup — replacing its rows (saved to a file first) or onto another shard', function () {
    $backup = ($this->conn)($this->backupShard);                                          // the shard's backup, restored as its own database
    $this->rows->copy($this->glow->id, ($this->conn)($this->one), $backup);
    $good = $this->rows->checksum($this->glow->id, $backup);
    // Then a mistake: the business's customers are gone.
    DB::connection(($this->conn)($this->one))->table('contacts')->where('tenant_id', $this->glow->id)->delete();

    $this->artisan('tenants:restore', ['handle' => 'glow-salon', '--from' => $backup, '--force' => true])
        ->expectsOutputToContain('Not restored: Restoring onto its own shard replaces what it has now')->assertFailed();
    $this->artisan('tenants:restore', ['handle' => 'glow-salon', '--from' => $backup, '--replace' => true, '--force' => true])
        ->expectsOutputToContain('identical to the backup')->assertSuccessful();
    expect($this->rows->checksum($this->glow->id, ($this->conn)($this->one)))->toBe($good)
        ->and(Storage::disk('local')->files('restore-safety'))->toHaveCount(1)
        ->and($this->rows->counts($this->other->id, ($this->conn)($this->one)))->not->toBe([]);

    // Onto another shard instead: the current rows stay where they are for 7 days.
    $this->artisan('tenants:restore', ['handle' => 'glow-salon', '--from' => $backup, '--to' => $this->two->name, '--force' => true])->assertSuccessful();
    expect(Tenant::query()->find($this->glow->id)->shard_id)->toBe($this->two->id)
        ->and($this->rows->counts($this->glow->id, ($this->conn)($this->one)))->not->toBe([]);
});

it('removes an archived business’s data for good — never an active one', function () {
    $provisioner = app(TenantProvisioner::class);
    expect(fn () => $provisioner->purge($this->glow->id))->toThrow(DomainException::class, 'Only an archived business can be removed for good.');
    Storage::fake((string) config('filesystems.default'));
    Storage::disk((string) config('filesystems.default'))->put('tenants/'.$this->glow->id.'/photo.jpg', 'x');
    $provisioner->archive($this->glow->id);
    $provisioner->purge($this->glow->id);
    expect($this->rows->counts($this->glow->id, ($this->conn)($this->one)))->toBe([])
        ->and(Storage::disk((string) config('filesystems.default'))->exists('tenants/'.$this->glow->id.'/photo.jpg'))->toBeFalse()
        ->and(Tenant::query()->find($this->glow->id)->purged_at)->not->toBeNull()
        ->and($this->rows->counts($this->other->id, ($this->conn)($this->one)))->not->toBe([]);
});
