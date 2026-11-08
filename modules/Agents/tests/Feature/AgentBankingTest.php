<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Wurk\Agents\Support\AgentBanking;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Identity\Models\Identity;
use Wurk\Tenancy\Support\HandleRules;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->agent = app(AgentRoster::class)->create('082 000 0001', 'Dineo', 'Agent', null, null);
    $this->me = Identity::query()->findOrFail($this->agent->identity_id);
    $this->area = fn (string $path = '') => 'http://'.config('wurk.domains.app').'/agent'.$path;
    $this->details = ['holder' => 'D Agent', 'bank' => 'FNB', 'branch_code' => '250655', 'account_type' => 'cheque', 'account' => '6212 3456 789'];
});

it('is for agents only', function () {
    $this->get(($this->area)())->assertRedirect();                                  // signed out → sign in
    $this->actingAs($this->person(['phone' => '+27829999999', 'email' => 'x@example.com']))->get(($this->area)())->assertNotFound(); // not an agent
    $this->actingAs($this->me)->get(($this->area)())->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Home')
        ->where('code', $this->agent->code)->where('link', route('agents.link', ['code' => $this->agent->code]))->where('hasBank', false));
    expect(HandleRules::problem('agent'))->not->toBeNull(); // no business can be called "agent"
});

it('saves banking details only after a code to the agent’s phone — encrypted, masked, never logged', function () {
    $this->actingAs($this->me)->post(($this->area)('/bank'), [...$this->details, 'branch_code' => '12'])->assertSessionHasErrors(['branch_code' => 'Branch codes have 6 digits.']);
    $this->actingAs($this->me)->post(($this->area)('/bank'), $this->details)->assertSessionHas('status');
    expect(FakeOtpChannel::$codes)->toHaveKey('+27820000001')                                          // to their phone
        ->and(json_encode(session()->all()))->not->toContain('62123456789')->not->toContain('6212 3456 789') // held encrypted meanwhile
        ->and(DB::table('agent_bank_accounts')->count())->toBe(0);                                      // nothing saved yet
    $this->actingAs($this->me)->post(($this->area)('/bank/confirm'), ['code' => '000000'])->assertSessionHasErrors(['code' => 'That code isn’t right, or it has expired. Ask for a new one.']);
    $this->actingAs($this->me)->post(($this->area)('/bank/confirm'), ['code' => FakeOtpChannel::$codes['+27820000001']])->assertRedirect('/agent/bank');

    $row = DB::table('agent_bank_accounts')->sole();
    expect($row->account_encrypted)->not->toContain('62123456789')->and($row->account_last4)->toBe('6789')
        ->and(app(AgentBanking::class)->accountNumber($this->agent->id))->toBe('62123456789')           // in full only for the payout batch
        ->and(json_encode(PlatformAuditEvent::query()->where('action', 'agents.bank_changed')->get()->map->getAttributes()))->not->toContain('62123456789');
    $this->actingAs($this->me)->get(($this->area)('/bank'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Bank')
        ->where('current.last4', '6789')->where('current.bank', 'FNB')->where('current.changed_at', now()->toDateString())->missing('current.account'));
});
