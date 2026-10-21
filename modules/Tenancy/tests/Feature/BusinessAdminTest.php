<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Identity\Mail\InvitationMail;
use Wurk\Identity\Models\Invitation;
use Wurk\Tenancy\Models\Tenant;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
});

function newBusiness(array $overrides = []): array
{
    return ['name' => 'Bright Smiles Dental', 'handle' => 'bright-smiles', 'country' => 'ZA', 'owner_first_name' => 'Sipho', 'owner_last_name' => 'Dlamini', 'owner_contact' => 'sipho@brightsmiles.co.za', ...$overrides];
}

it('lists and filters businesses for staff who may see them', function () {
    $this->createTenant('glow-spa', name: 'Glow Spa');
    $support = $this->staffWithRole('support');

    $this->actingAs($support)->get($this->admin('/businesses?search=spa'))
        ->assertInertia(fn (Assert $p) => $p->component('Tenancy::Admin/Businesses')->where('businesses.total', 1)->where('businesses.data.0.name', 'Glow Spa')->where('canCreate', false));
});

it('creates a business and emails its owner an invitation', function () {
    $this->actingAs($this->staffWithRole('super-admin'))->post($this->admin('/businesses'), newBusiness())
        ->assertRedirect()->assertSessionHas('status');

    $tenant = Tenant::query()->where('handle', 'bright-smiles')->firstOrFail();
    expect($tenant->status)->toBe('active')
        ->and(Invitation::query()->where('tenant_id', $tenant->id)->value('role'))->toBe('owner');
    Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('sipho@brightsmiles.co.za') && $m->joining === 'Bright Smiles Dental');
});

it('refuses business creation to staff without permission and validates the handle', function () {
    $this->actingAs($this->staffWithRole('support'))->post($this->admin('/businesses'), newBusiness())->assertForbidden();

    $admin = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->actingAs($admin)->post($this->admin('/businesses'), newBusiness(['handle' => 'glow-salon']))->assertSessionHasErrors('handle');
    $this->actingAs($admin)->getJson($this->admin('/businesses/check-handle?handle=glow-salon'))->assertJson(['available' => false]);
    $this->actingAs($admin)->getJson($this->admin('/businesses/check-handle?handle=new-place'))->assertJson(['available' => true]);
});

it('suspends and reactivates with a reason recorded in the audit log', function () {
    $admin = $this->staffWithRole('super-admin');

    $this->actingAs($admin)->post($this->admin("/businesses/{$this->glow->id}/suspend"), ['reason' => 'x'])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post($this->admin("/businesses/{$this->glow->id}/suspend"), ['reason' => 'Chargeback under review'])->assertSessionHas('status');

    expect($this->glow->fresh()->status)->toBe('suspended');
    $this->getJson('http://glow-salon.'.config('wurk.domains.root').'/any')->assertNotFound();

    $event = PlatformAuditEvent::query()->where('action', 'tenant.status_changed')->latest('seq')->firstOrFail();
    expect($event->changes)->toMatchArray(['to' => 'suspended', 'reason' => 'Chargeback under review'])
        ->and($event->actor_identity_id)->toBe($admin->id);

    $this->actingAs($admin)->post($this->admin("/businesses/{$this->glow->id}/reactivate"), ['reason' => 'Resolved with the bank'])->assertSessionHas('status');
    expect($this->glow->fresh()->status)->toBe('active');
});

it('shows a business detail page with a link to its audit trail', function () {
    $this->actingAs($this->staffWithRole('support'))->get($this->admin("/businesses/{$this->glow->id}"))
        ->assertInertia(fn (Assert $p) => $p->component('Tenancy::Admin/Business')->where('business.handle', 'glow-salon')->where('canManage', false)
            ->where('auditUrl', fn ($u) => str_contains($u, 'subject='.$this->glow->id)));
});
