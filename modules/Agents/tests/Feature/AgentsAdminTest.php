<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Agents\Models\Agent;
use Wurk\Audit\Models\PlatformAuditEvent;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->admin = $this->staffWithRole('super-admin');
    $this->add = fn (string $first, string $contact, ?string $sponsor = null, ?string $months = null) => $this->actingAs($this->admin)
        ->post($this->admin('/agents'), ['first_name' => $first, 'last_name' => 'Agent', 'contact' => $contact, 'sponsor_id' => $sponsor, 'months' => $months]);
    $this->id = fn (string $first) => Agent::query()->whereIn('identity_id', DB::table('identities')->where('first_name', $first)->pluck('id'))->value('id');
});

it('builds the sponsor hierarchy, refusing loops and duplicates, and logs every change', function () {
    ($this->add)('Ayanda', 'ayanda@example.com')->assertSessionHas('status', 'Agent added.');
    ($this->add)('Bongi', '0820000002', ($this->id)('Ayanda'), '24');
    ($this->add)('Chris', 'chris@example.com', ($this->id)('Bongi'));
    ($this->add)('Again', 'ayanda@example.com')->assertSessionHasErrors(['agent' => 'This person is already an agent.']);

    $this->actingAs($this->admin)->get($this->admin('/agents'))->assertInertia(fn (Assert $p) => $p->component('Agents::Admin/Agents')->has('agents', 3)
        ->where('agents.0.name', 'Ayanda Agent')->where('agents.0.direct', 1)->where('agents.0.team', 2)->where('agents.0.months', null)
        ->where('agents.1.sponsor', 'Ayanda Agent')->where('agents.1.months', 24)->where('agents.2.sponsor', 'Bongi Agent'));
    expect(Agent::query()->pluck('code')->every(fn ($c) => preg_match('/^[2-9A-HJ-NP-Z]{7}$/', $c) === 1))->toBeTrue();

    $a = ($this->id)('Ayanda');
    $this->actingAs($this->admin)->put($this->admin("/agents/{$a}"), ['sponsor_id' => ($this->id)('Chris'), 'status' => 'active', 'months' => null])
        ->assertSessionHasErrors(['agent' => 'That would put the agent under their own team.']);
    $this->actingAs($this->admin)->put($this->admin('/agents/'.($this->id)('Chris')), ['sponsor_id' => null, 'status' => 'suspended', 'months' => '12'])->assertSessionHas('status', 'Saved.');
    expect(Agent::query()->find(($this->id)('Chris'))->only(['sponsor_id', 'status', 'commission_months']))->toBe(['sponsor_id' => null, 'status' => 'suspended', 'commission_months' => 12]);
    expect(PlatformAuditEvent::query()->whereIn('action', ['agents.created', 'agents.moved', 'agents.updated'])->pluck('action')->countBy()->all())
        ->toMatchArray(['agents.created' => 3, 'agents.moved' => 1, 'agents.updated' => 1]);
});

it('lets finance see agents but not change them', function () {
    ($this->add)('Ayanda', 'ayanda@example.com');
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $this->actingAs($finance)->get($this->admin('/agents'))->assertInertia(fn (Assert $p) => $p->has('agents', 1)->where('canManage', false));
    $this->actingAs($finance)->post($this->admin('/agents'), ['first_name' => 'X', 'last_name' => 'Y', 'contact' => 'x@example.com'])->assertForbidden();
});
