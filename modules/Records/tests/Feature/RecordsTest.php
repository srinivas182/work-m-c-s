<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\InteractsWithShards;
use Wurk\Records\Models\Document;
use Wurk\Records\Models\Patient;
use Wurk\Records\Support\Records;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('records');
    $this->createShards(1);
    $this->clinic = $this->createTenant('mamelodi-physio');
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->clinic, $fn);
    $this->patient = ($this->in)(fn () => Patient::query()->create(['name' => 'Lerato Mokoena', 'email' => 'lerato@example.com']));
    $this->file = fn (string $text) => UploadedFile::fake()->createWithContent('report.txt', $text);
    $this->logs = fn () => ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('record_access_logs')->orderBy('id')->pluck('action')->all());
});

it('stores files encrypted, and logs every read', function () {
    $doc = ($this->in)(fn () => app(Records::class)->upload($this->patient, 'Assessment', ($this->file)('Lower back strain, grade 2'), 'Dr Naidoo'));
    expect(Storage::disk('records')->get($doc->path))->not->toContain('Lower back strain')
        ->and(($this->in)(fn () => app(Records::class)->read($doc, 'view', 'staff', 'Dr Naidoo')))->toBe('Lower back strain, grade 2')
        ->and(($this->logs)())->toBe(['upload', 'view']);
});

it('corrects by adding a version — the original is kept', function () {
    [$v1, $v2] = ($this->in)(function () {
        $v1 = app(Records::class)->upload($this->patient, 'Assessment', ($this->file)('grade 2'), 'Dr Naidoo');
        $v2 = app(Records::class)->upload($this->patient, 'Assessment', ($this->file)('grade 1'), 'Dr Naidoo', corrects: $v1, note: 'Grade corrected');

        return [$v1, $v2];
    });
    expect([$v2->version, $v2->original_id, $v2->note])->toBe([2, $v1->id, 'Grade corrected'])
        ->and(($this->in)(fn () => Document::query()->count()))->toBe(2)
        ->and(($this->logs)())->toBe(['upload', 'correct']);
});

it('keeps records for 6 years from the most recent document, then flags them for the owner to remove', function () {
    $doc = ($this->in)(fn () => app(Records::class)->upload($this->patient, 'Assessment', ($this->file)('notes'), 'Dr Naidoo'));
    ($this->in)(fn () => expect(fn () => app(Records::class)->remove($this->patient->refresh(), 'Owner'))->toThrow(RuntimeException::class, 'These records must be kept until '.now()->addYears(6)->format('j F Y').'.'));

    $this->travel(5)->years();
    ($this->in)(fn () => app(Records::class)->upload($this->patient->refresh(), 'Follow-up', ($this->file)('better'), 'Dr Naidoo')); // the 6 years restart
    $this->travel(2)->years();
    $this->artisan('records:flag-due')->expectsOutput('0 patient(s) flagged.'); // only 2 years since the latest

    $this->travel(5)->years();
    $this->artisan('records:flag-due')->expectsOutput('1 patient(s) flagged.');
    expect(DB::table('record_retention')->count())->toBe(1);
    ($this->in)(fn () => app(Records::class)->remove($this->patient->refresh(), 'Owner'));
    expect(Storage::disk('records')->exists($doc->path))->toBeFalse()
        ->and(($this->in)(fn () => [Patient::query()->count(), Document::query()->count()]))->toBe([0, 0])->and(DB::table('record_retention')->count())->toBe(0)
        ->and(($this->logs)())->toBe(['upload', 'upload', 'flagged', 'removed']); // the log keeps that it happened
});
