<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Records\Models\Document;
use Wurk\Records\Models\Patient;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Storage::fake('records');
    Mail::fake();
    $this->createShards(1);
    $this->clinic = $this->createTenant('mamelodi-physio');
    $p = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->clinic->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->clinic);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->clinic, $fn);
    ($this->in)(fn () => app(Team::class)->add($this->owner->id, 'Thandi', 'owner'));
    $this->base = $this->app('/mamelodi-physio/records');
});

it('explains how to switch on health practice first', function () {
    $this->actingAs($this->owner)->get($this->base)->assertInertia(fn (Assert $p) => $p->component('Records::Index')->where('health', false));
    $this->actingAs($this->owner)->post($this->base, ['name' => 'Lerato'])->assertNotFound();
});

it('adds patients and documents, corrects with a note, logs who opened what, and keeps records through retention', function () {
    ($this->in)(fn () => BookingSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'health_practice' => true]));
    $this->actingAs($this->owner)->post($this->base, ['name' => 'Lerato Mokoena', 'email' => 'lerato@example.com'])->assertSessionHas('status', 'Patient added.');
    $patient = ($this->in)(fn () => Patient::query()->sole());
    $page = "{$this->base}/{$patient->id}";

    $this->actingAs($this->owner)->post("{$page}/documents", ['title' => 'Assessment', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'grade 2')])->assertSessionHas('status', 'Document added.');
    $v1 = ($this->in)(fn () => Document::query()->sole());
    $this->actingAs($this->owner)->post("{$page}/documents", ['title' => 'Assessment', 'corrects' => $v1->id, 'file' => UploadedFile::fake()->createWithContent('a.txt', 'grade 1')])
        ->assertSessionHasErrors(['note' => 'Say what the correction changes — the original is kept.']);
    $this->actingAs($this->owner)->post("{$page}/documents", ['title' => 'Assessment', 'corrects' => $v1->id, 'note' => 'Grade corrected', 'file' => UploadedFile::fake()->createWithContent('a.txt', 'grade 1')])
        ->assertSessionHas('status', 'Correction added — the original is kept.');
    $this->actingAs($this->owner)->get("{$page}/documents/{$v1->id}")->assertOk()->assertSee('grade 2')->assertHeader('Cache-Control', 'no-store, private');
    $this->actingAs($this->owner)->post("{$page}/link")->assertSessionHas('status', 'Private link sent. The patient gets a one-time code when they open it.');
    $this->actingAs($this->owner)->delete("{$page}/remove")->assertSessionHasErrors('remove');

    $this->actingAs($this->owner)->get($page)->assertInertia(fn (Assert $p) => $p->component('Records::Patient')
        ->has('documents', 2)->where('canRemove', true)->where('log.0.action', 'link_sent')->where('log.1', fn ($l) => $l['action'] === 'view' && $l['who'] === 'Thandi Mokoena'));
});
