<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Platform\Contracts\BusinessBadge;
use Wurk\Platform\Events\BadgeChanged;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Trust\Models\BusinessVerification;

/*
 * Sprint 25.4: the verified business badge. The owner's phone (confirmed by code) and their identity (ID and selfie,
 * checked by HQ) make "Verified by WURK"; a registry match adds "Registered business". Documents are encrypted,
 * every view is logged, and they're deleted 90 days after the decision. Lapses after two years; never when suspended.
 */

uses(InteractsWithShards::class, InteractsWithIdentity::class);

beforeEach(function () {
    Storage::fake('trust');
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Zodwa', 'owner'));
    $this->hq = $this->staffWithRole('super-admin', 'ops@wurk.co.za');
    $this->page = $this->app('/glow-salon/settings/verified');
    $this->badge = fn () => app(BusinessBadge::class)->for($this->glow->id);
});

it('verifies a sole trader once their phone is confirmed and HQ approves their ID, and shows what was checked', function () {
    $this->actingAs($this->owner)->get($this->page)->assertInertia(fn (Assert $p) => $p->component('Trust::Verified')
        ->where('badge.verified', false)->where('checks.phone', false)->where('checks.identity.status', 'none'));

    $this->actingAs($this->owner)->post($this->page.'/identity', ['id_document' => UploadedFile::fake()->image('id.jpg'), 'selfie' => UploadedFile::fake()->image('me.jpg')])
        ->assertSessionHas('status', 'Sent. WURK checks it within two working days.');
    $v = BusinessVerification::query()->findOrFail($this->glow->id);
    expect($v->identity_status)->toBe('pending')->and($v->documents)->toHaveCount(2);
    $stored = Storage::disk('trust')->get($v->documents[0]['path']);
    expect($stored)->not->toContain('JFIF')->not->toBe(''); // encrypted at rest

    // HQ: the queue, the documents (logged), a refusal needs a reason, then approval.
    $this->actingAs($this->hq)->get($this->admin('/verification'))->assertInertia(fn (Assert $p) => $p->component('Trust::Admin/Queue')->where('queue.0.business', 'Glow Salon'));
    $this->actingAs($this->hq)->get($this->admin("/verification/{$this->glow->id}/documents/id"))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->assertDatabaseHas('audit_events', ['action' => 'trust.document_viewed', 'subject_id' => $this->glow->id]);
    $this->actingAs($this->hq)->post($this->admin("/verification/{$this->glow->id}/identity"), ['approve' => false, 'reason' => ''])->assertSessionHasErrors('reason');
    Event::fake([BadgeChanged::class]);
    $this->actingAs($this->hq)->post($this->admin("/verification/{$this->glow->id}/identity"), ['approve' => true])->assertSessionHas('status', 'Approved.');
    Event::assertDispatched(BadgeChanged::class, fn (BadgeChanged $e) => $e->tenantId === $this->glow->id); // the site's footer is re-rendered

    expect(($this->badge)()['verified'])->toBeFalse(); // the phone isn't confirmed yet
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    expect(($this->badge)())->toMatchArray(['verified' => true, 'registered' => false, 'checked_on' => now()->toDateString()]);
    $this->get('http://'.config('wurk.domains.root').'/verified/glow-salon')->assertOk()->assertSee('Glow Salon is verified by WURK')
        ->assertSee('confirmed with a one-time code')->assertDontSee('official company register');
});

it('adds "Registered business" once HQ matches the number, and refuses an unreadable one', function () {
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    BusinessVerification::query()->create(['tenant_id' => $this->glow->id, 'identity_status' => 'approved', 'identity_decided_at' => now(), 'identity_expires_at' => now()->addYears(2)]);
    $this->actingAs($this->owner)->post($this->page.'/registry', ['registry_number' => 'nope'])->assertSessionHasErrors('registry_number');
    $this->actingAs($this->owner)->post($this->page.'/registry', ['registry_number' => '2019/123456/07'])->assertSessionHasNoErrors();
    $this->actingAs($this->hq)->post($this->admin("/verification/{$this->glow->id}/registry"), ['approve' => true]);

    expect(($this->badge)())->toMatchArray(['verified' => true, 'registered' => true]);
    $this->get('http://'.config('wurk.domains.root').'/verified/glow-salon')->assertSee('official company register');
});

it('lapses after two years, never shows for a suspended business, and deletes documents 90 days after the decision', function () {
    $this->owner->forceFill(['phone_verified_at' => now()])->save();
    $this->actingAs($this->owner)->post($this->page.'/identity', ['id_document' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'), 'selfie' => UploadedFile::fake()->image('me.jpg')]);
    $this->actingAs($this->owner)->post($this->page.'/identity', ['id_document' => UploadedFile::fake()->image('id.jpg'), 'selfie' => UploadedFile::fake()->image('me.jpg')])
        ->assertSessionHasErrors(['id_document' => 'WURK is still checking what you sent.']);
    $this->actingAs($this->hq)->post($this->admin("/verification/{$this->glow->id}/identity"), ['approve' => true]);
    $paths = array_column(BusinessVerification::query()->findOrFail($this->glow->id)->documents, 'path');

    $this->travel(89)->days();
    $this->artisan('trust:purge-documents')->assertSuccessful();
    Storage::disk('trust')->assertExists($paths[0]);
    $this->travel(2)->days();
    $this->artisan('trust:purge-documents')->expectsOutput('1 business(es) cleared.');
    Storage::disk('trust')->assertMissing($paths[0]);
    expect(BusinessVerification::query()->findOrFail($this->glow->id)->documents)->toBeNull()->and(($this->badge)()['verified'])->toBeTrue(); // the decision stays

    $this->glow->update(['status' => 'suspended']);
    expect(($this->badge)()['verified'])->toBeFalse();
    $this->glow->update(['status' => 'active']);
    $this->travel(2)->years();
    expect(($this->badge)()['verified'])->toBeFalse(); // redone every two years
});

it('keeps the checks to the owner and HQ', function () {
    $staff = $this->person(['email' => 'lindiwe@glow.co.za', 'phone' => '+27821234500']);
    $this->member($staff, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($staff->id, 'Lindiwe', 'staff'));
    $this->actingAs($staff)->get($this->page)->assertNotFound();
    BusinessVerification::query()->create(['tenant_id' => $this->glow->id, 'identity_status' => 'pending', 'documents' => [['kind' => 'id', 'path' => 'x.bin', 'mime' => 'image/jpeg']]]);
    $support = $this->staffWithRole('support', 'help@wurk.co.za');
    $this->actingAs($support)->get($this->admin("/verification/{$this->glow->id}/documents/id"))->assertForbidden();
});
