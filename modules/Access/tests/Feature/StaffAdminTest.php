<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\PlatformRoleAssignment;
use Wurk\Identity\Mail\InvitationMail;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    Mail::fake();
    $this->admin = $this->staffWithRole('super-admin');
});

it('lists staff with roles and invites a colleague', function () {
    $this->actingAs($this->admin)->get($this->admin('/staff'))
        ->assertInertia(fn (Assert $p) => $p->component('Access::Admin/Staff')->where('staff.0.roles', ['super-admin'])->where('staff.0.mfa', true));

    $this->actingAs($this->admin)->post($this->admin('/staff/invitations'), ['email' => 'lerato@wurk.co.za', 'role' => 'support'])->assertSessionHas('status');
    Mail::assertSent(InvitationMail::class, fn ($m) => $m->hasTo('lerato@wurk.co.za') && $m->joining === 'WURK');
});

it('changes roles but always keeps a super admin', function () {
    $support = $this->staffWithRole('support', 'support@wurk.co.za');

    $this->actingAs($this->admin)->put($this->admin("/staff/{$support->id}/roles"), ['roles' => ['support', 'finance']])->assertSessionHasNoErrors();
    expect(PlatformRoleAssignment::query()->where('identity_id', $support->id)->pluck('role_key')->sort()->values()->all())->toBe(['finance', 'support']);

    $this->actingAs($this->admin)->put($this->admin("/staff/{$this->admin->id}/roles"), ['roles' => ['support']])
        ->assertSessionHasErrors(['roles' => 'WURK must always have at least one super admin.']);
});

it('deactivates staff, never yourself or the last super admin', function () {
    $support = $this->staffWithRole('support', 'support@wurk.co.za');

    $this->actingAs($this->admin)->post($this->admin("/staff/{$this->admin->id}/deactivate"))->assertSessionHasErrors('staff');
    $this->actingAs($this->admin)->post($this->admin("/staff/{$support->id}/deactivate"))->assertSessionHas('status');
    $this->actingAs($support->fresh())->get($this->admin('/dashboard'))->assertForbidden();
});

it('keeps people without staff.manage out of staff management', function () {
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->admin('/staff'))->assertForbidden();
});

it('creates custom staff roles from platform permissions only, and deletes unused ones', function () {
    $this->actingAs($this->admin)->post($this->admin('/roles'), ['name' => 'Auditor', 'permissions' => ['platform-audit.view']])->assertSessionHas('status');
    $this->actingAs($this->admin)->post($this->admin('/roles'), ['name' => 'Sneaky', 'permissions' => ['team.manage']])->assertSessionHasErrors('permissions');

    $role = PlatformRole::query()->where('name', 'Auditor')->firstOrFail();
    $this->actingAs($this->admin)->get($this->admin('/roles'))->assertInertia(fn (Assert $p) => $p->has('custom', 1)->has('system', 4));
    $this->actingAs($this->admin)->delete($this->admin("/roles/{$role->id}"))->assertSessionHas('status');
    expect(PlatformRole::query()->count())->toBe(0);
});
