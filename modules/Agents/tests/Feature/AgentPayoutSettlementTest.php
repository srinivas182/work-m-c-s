<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Mail\AgentPayoutBouncedMail;
use Wurk\Agents\Mail\AgentRemittanceMail;
use Wurk\Agents\Support\AgentCommission;
use Wurk\Agents\Support\AgentPayouts;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Audit\Models\PlatformAuditEvent;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->ayanda = app(AgentRoster::class)->create('ayanda@example.com', 'Ayanda', 'Agent', null, null);
    DB::table('agent_bank_accounts')->insert(['agent_id' => $this->ayanda->id, 'holder' => 'A Agent', 'bank' => 'FNB', 'branch_code' => '250655', 'account_type' => 'cheque',
        'account_encrypted' => Crypt::encryptString('62123456789'), 'account_last4' => '6789', 'changed_at' => now()->subDays(30), 'created_at' => now(), 'updated_at' => now()]);
    $this->earn = fn (int $cents) => DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $this->ayanda->id, 'tenant_id' => $this->glow->id,
        'payment_id' => (string) Str::ulid(), 'level' => 0, 'kind' => 'earned', 'cents' => $cents, 'bp' => 1000, 'available_at' => now()->subDay(), 'reference' => 'payment', 'created_at' => now()]);
    $this->pay = app(AgentPayouts::class);
    $this->bal = fn () => app(AgentCommission::class)->balance($this->ayanda->id);
});

it('marks a batch paid once, sending a remittance advice that covers what’s new since the previous payout', function () {
    ($this->earn)(30000);
    $first = $this->pay->create(null);
    $this->pay->markPaid($first);
    Mail::assertSent(AgentRemittanceMail::class, fn (AgentRemittanceMail $m) => $m->hasTo('ayanda@example.com') && $m->cents === 30000
        && str_starts_with($m->reference, 'WURK-AG-') && $m->bank === 'FNB ·· 6789' && $m->covers === [['business' => 'Glow Salon', 'cents' => 30000]]);
    expect(fn () => $this->pay->markPaid($first))->toThrow(DomainException::class, 'Only a batch waiting to be paid can be marked paid.')
        ->and(fn () => $this->pay->cancel($first))->toThrow(DomainException::class);

    $this->travel(1)->minutes();
    ($this->earn)(10000);
    $this->travel(1)->minutes();
    $this->pay->markPaid($this->pay->create(null));
    Mail::assertSent(AgentRemittanceMail::class, fn (AgentRemittanceMail $m) => $m->cents === 10000 && $m->covers === [['business' => 'Glow Salon', 'cents' => 10000]]); // only the new R100
    expect(($this->bal)())->toBe(['pending' => 0, 'available' => 0, 'paid_out' => 40000]);
});

it('returns a bounced payment to the agent’s balance once, and asks them to check their banking details', function () {
    ($this->earn)(30000);
    $batch = $this->pay->create(null);
    $this->pay->markPaid($batch);
    $item = (string) DB::table('agent_payout_items')->where('batch_id', $batch)->value('id');
    expect(fn () => $this->pay->markBounced($batch, 'nonsense'))->toThrow(DomainException::class);
    $this->pay->markBounced($batch, $item);
    expect(($this->bal)())->toBe(['pending' => 0, 'available' => 30000, 'paid_out' => 0]);
    Mail::assertSent(AgentPayoutBouncedMail::class, fn (AgentPayoutBouncedMail $m) => $m->hasTo('ayanda@example.com') && $m->cents === 30000 && $m->link === route('agent.bank'));
    expect(fn () => $this->pay->markBounced($batch, $item))->toThrow(DomainException::class, 'Only a payment in a paid batch can be marked bounced, once.');

    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za'); // and through Admin, it's logged
    expect(PlatformAuditEvent::query()->whereIn('action', ['agents.batch_paid', 'agents.payout_bounced'])->count())->toBe(2);
    $this->actingAs($finance)->get($this->admin('/agents/payouts'))->assertInertia(fn ($p) => $p->where('batches.0.items.0.status', 'bounced'));
});

it('renders both emails in plain words', function () {
    $remit = (new AgentRemittanceMail('Ayanda Agent', 30000, 'WURK-AG-20261108-001', 'FNB ·· 6789', [['business' => 'Glow Salon', 'cents' => 30000]]))->render();
    expect($remit)->toContain('WURK has paid you R300.00 into your FNB ·· 6789 account.')->toContain('Bank reference: WURK-AG-20261108-001')->toContain('- Glow Salon: R300.00');
    $bounced = (new AgentPayoutBouncedMail('Ayanda Agent', 30000, 'WURK-AG-20261108-001', 'https://app.example/agent/bank'))->render();
    expect($bounced)->toContain('couldn’t be paid — the bank returned it')->toContain('https://app.example/agent/bank');
});
