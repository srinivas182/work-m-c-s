<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\PlatformAccess;
use Wurk\Access\Actions\Team;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Models\Membership;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->context = app(TenantContext::class);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->context->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Thandi', SystemRoles::OWNER));
});

it('shows owners their business audit log, newest first', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon/audit'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('Audit::Index')
            ->where('scope', 'Glow Salon')
            ->where('events.data.0.action', 'role.assigned'));
});

it('filters by activity group and exports CSV', function () {
    $this->context->run($this->glow, fn () => app(AuditLog::class)->record('identity.custom_probe'));

    $this->actingAs($this->owner)->get($this->app('/glow-salon/audit?action=role.'))
        ->assertInertia(fn (Assert $p) => $p->where('events.total', 1));

    $csv = $this->actingAs($this->owner)->get($this->app('/glow-salon/audit?format=csv'));
    $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->streamedContent())->toStartWith('seq,occurred_at,actor')->toContain('role.assigned');
});

it('hides the business audit log from staff without permission and from outsiders', function () {
    $desk = $this->person(['email' => 'desk@glow.co.za', 'phone' => null]);
    Membership::query()->create(['identity_id' => $desk->id, 'tenant_id' => $this->glow->id, 'kind' => 'staff', 'status' => 'active']);
    $this->context->run($this->glow, fn () => app(Team::class)->add($desk->id, 'Desk', SystemRoles::STAFF));

    $this->actingAs($desk)->get($this->app('/glow-salon/audit'))->assertNotFound();
    $this->actingAs($this->person(['email' => 'out@x.com', 'phone' => null]))->get($this->app('/glow-salon/audit'))->assertNotFound();
});

it('shows WURK support staff the platform audit log, and nobody else', function () {
    $staff = $this->staff();
    app(PlatformAccess::class)->grant($staff->id, 'support');

    $this->actingAs($staff)->get($this->admin('/audit'))
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->where('scope', 'WURK platform')->where('events.data', fn ($d) => collect($d)->contains('action', 'tenant.created')));

    $this->actingAs($this->owner)->get($this->admin('/audit'))->assertForbidden();
});

it('returns a request id on every response', function () {
    $this->get($this->app('/sign-in'))->assertHeader('X-Request-Id');
    $this->get($this->app('/sign-in'), ['X-Request-Id' => 'lb-trace-12345678'])->assertHeader('X-Request-Id', 'lb-trace-12345678');
});

it('shows the audit log in navigation only to people allowed to see it', function () {
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))
        ->assertInertia(fn (Assert $p) => $p->where('navigation', fn ($n) => collect($n)->contains('label', 'Audit log')));
});
