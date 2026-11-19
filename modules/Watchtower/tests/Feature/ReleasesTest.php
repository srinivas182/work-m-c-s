<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Watchtower\Mail\ReleaseMail;

uses(InteractsWithIdentity::class);

/*
 * Sprint 17.2: after migrating, a deploy runs each version's release steps once per environment (oldest first, stopping
 * at the first failure), and the steps for every deploy each time; then it reports how it went, and HQ is told.
 */

beforeEach(function () {
    Mail::fake();
    config(['wurk.watchtower.recipients' => ['hq@example.test'], 'wurk.watchtower.ai' => false]);
    $this->log = $log = new ArrayObject;
    foreach (['test:one', 'test:two', 'test:every', 'test:broken'] as $name) {
        Artisan::command($name, function () use ($name, $log) {
            $log[] = $name;

            return $name === 'test:broken' ? 1 : 0;
        });
    }
    config(['release.every' => ['test:every'], 'release.once' => ['0.10.0' => ['test:two'], '0.9.0' => ['test:one']]]);
});

it('runs each version’s steps once, oldest first, and the every-deploy steps each time', function () {
    $this->artisan('release:tasks', ['--dry-run' => true])->expectsOutput('0.9.0: test:one')->expectsOutput('0.10.0: test:two')->expectsOutput('every deploy: test:every')->assertSuccessful();
    $this->artisan('release:tasks')->expectsOutputToContain('3 release steps done.')->assertSuccessful();
    expect($this->log->getArrayCopy())->toBe(['test:one', 'test:two', 'test:every']);

    $this->log->exchangeArray([]);
    $this->artisan('release:tasks')->expectsOutputToContain('1 release step done.')->assertSuccessful();
    expect($this->log->getArrayCopy())->toBe(['test:every'])->and(DB::table('release_tasks')->pluck('key')->sort()->values()->all())->toBe(['0.10.0 test:two', '0.9.0 test:one']);
});

it('stops at the first step that fails, so the deploy stops too, and tries it again next time', function () {
    config(['release.once' => ['0.9.0' => ['test:one'], '0.10.0' => ['test:broken', 'test:two']]]);
    $this->artisan('release:tasks')->expectsOutputToContain('Stopped: “test:broken” failed.')->assertFailed();
    expect($this->log->getArrayCopy())->toBe(['test:one', 'test:broken']) // nothing after it
        ->and(DB::table('release_tasks')->pluck('key')->all())->toBe(['0.9.0 test:one']);
});

it('records each deploy, tells HQ, and shows it on Admin → System', function () {
    $this->artisan('release:finished', ['version' => 'v1.0.0', '--environment' => 'staging', '--status' => 'gone'])->expectsOutputToContain('Status must be one of')->assertFailed();
    $this->artisan('release:finished', ['version' => 'v1.0.0', '--environment' => 'staging'])->assertSuccessful();
    $this->artisan('release:finished', ['version' => 'v1.0.1', '--environment' => 'production', '--status' => 'rolled_back', '--note' => 'Health checks failed on 2 of 3 web containers'])->assertSuccessful();

    Mail::assertSent(ReleaseMail::class, fn (ReleaseMail $m) => $m->status === 'live' && $m->envelope()->subject === '[WURK] v1.0.0 is live on staging');
    Mail::assertSent(ReleaseMail::class, fn (ReleaseMail $m) => $m->envelope()->subject === '[WURK] v1.0.1 was rolled back on production'
        && str_contains($m->render(), 'the previous version was put back automatically') && str_contains($m->render(), 'Health checks failed on 2 of 3'));
    $this->actingAs($this->staffWithRole('engineering', 'eng@wurk.co.za'))->get($this->admin('/system'))->assertInertia(fn (Assert $p) => $p
        ->has('releases', 2)->where('releases.0.version', 'v1.0.1')->where('releases.0.status', 'rolled_back')->where('releases.1.environment', 'staging'));
});

it('names AWS Backup’s resources the way BACKUP_TARGETS lists them (17.4)', function () {
    $this->artisan('system:backup-taken', ['target' => 'arn:aws:rds:af-south-1:123456789012:db:wurk-production-shard-1', '--kind' => 'snapshot',
        '--location' => 'arn:aws:backup:af-south-1:123456789012:recovery-point:abc', '--size' => '5242880', '--by' => 'aws-backup'])->assertSuccessful();
    $this->artisan('system:backup-taken', ['target' => 'arn:aws:s3:::wurk-production-files-123456789012', '--kind' => 'files'])->assertSuccessful();
    expect(DB::table('watchtower_backups')->orderBy('target')->pluck('target')->all())->toBe(['wurk-production-files-123456789012', 'wurk-production-shard-1']);
});
