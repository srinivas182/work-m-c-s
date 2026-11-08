<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Agents\Support\AgentCommission;
use Wurk\Agents\Support\AgentPayouts;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Audit\Models\PlatformAuditEvent;

uses(InteractsWithIdentity::class);

beforeEach(function () {
    $roster = app(AgentRoster::class);
    $make = fn (string $first) => $roster->create(strtolower($first).'@example.com', $first, 'Agent', null, null);
    $this->credit = fn ($agent, int $cents) => DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $agent->id, 'tenant_id' => null,
        'payment_id' => (string) Str::ulid(), 'level' => 0, 'kind' => 'earned', 'cents' => $cents, 'bp' => 1000, 'available_at' => now()->subDay(), 'reference' => 'payment', 'created_at' => now()]);
    $this->bank = fn ($agent, string $holder, string $account, int $daysAgo) => DB::table('agent_bank_accounts')->updateOrInsert(['agent_id' => $agent->id], ['holder' => $holder, 'bank' => 'FNB',
        'branch_code' => '250655', 'account_type' => 'cheque', 'account_encrypted' => Crypt::encryptString($account), 'account_last4' => substr($account, -4),
        'changed_at' => now()->subDays($daysAgo), 'created_at' => now(), 'updated_at' => now()]);
    foreach (['Ayanda', 'Bongi', 'Chris', 'Dineo', 'Eve', 'Fezi'] as $n) {
        $this->{strtolower($n)} = $make($n);
    }
    ($this->credit)($this->ayanda, 30000);
    ($this->bank)($this->ayanda, '=HYPERLINK("x")', '62123456789', 10); // a formula in a name must not run in a spreadsheet
    ($this->credit)($this->bongi, 20000);
    ($this->bank)($this->bongi, 'B Agent', '62000000002', 2);
    ($this->credit)($this->chris, 10000);
    ($this->credit)($this->dineo, 5000);
    ($this->bank)($this->dineo, 'D Agent', '62000000004', 30);
    app(AgentRoster::class)->update($this->dineo, 'suspended', null);
    ($this->credit)($this->eve, -3000);
    ($this->bank)($this->eve, 'E Agent', '62000000005', 30);
    ($this->credit)($this->fezi, 10000);
    ($this->bank)($this->fezi, 'F Agent', '62000000006', 30);
    app(AgentPayouts::class)->setMinimum(15000);
    $this->finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
});

it('previews who is included, held and skipped — with reasons', function () {
    $p = app(AgentPayouts::class)->preview();
    expect(array_column($p['included'], 'name'))->toBe(['Ayanda Agent'])
        ->and(array_column($p['held'], 'name'))->toBe(['Bongi Agent'])->and($p['held'][0]['reason'])->toStartWith('Banking details changed on')
        ->and(array_column($p['skipped'], 'reason', 'name'))->toBe(['Chris Agent' => 'No banking details', 'Dineo Agent' => 'Suspended', 'Eve Agent' => 'Owes WURK', 'Fezi Agent' => 'Below the minimum payout']);
});

it('makes a batch that reserves each amount, downloads a safe CSV (logged), refuses after a bank change, and cancels back', function () {
    $this->actingAs($this->finance)->post($this->admin('/agents/payouts'))->assertSessionHas('status');
    expect(app(AgentCommission::class)->balance($this->ayanda->id))->toBe(['pending' => 0, 'available' => 0, 'paid_out' => 30000]);
    $this->actingAs($this->finance)->post($this->admin('/agents/payouts'))->assertSessionHasErrors(['batch' => 'Nobody is due a payout right now.']); // not twice

    $batch = DB::table('agent_payout_batches')->sole();
    expect($batch->reference)->toBe('WURK-AG-'.now()->format('Ymd').'-001')->and((int) $batch->total_cents)->toBe(30000);
    $csv = $this->actingAs($this->finance)->get($this->admin("/agents/payouts/{$batch->id}/csv"))->assertOk()->assertHeader('Cache-Control', 'no-store, private')->getContent();
    $rows = array_map('str_getcsv', array_filter(explode("\n", (string) $csv)));
    expect($rows[0])->toBe(['Account holder', 'Bank', 'Branch code', 'Account type', 'Account number', 'Amount', 'Reference'])
        ->and($rows[1])->toBe(["'=HYPERLINK(\"x\")", 'FNB', '250655', 'cheque', '62123456789', '300.00', $batch->reference])
        ->and(PlatformAuditEvent::query()->where('action', 'agents.batch_downloaded')->count())->toBeGreaterThanOrEqual(1);

    ($this->bank)($this->ayanda, 'A Agent', '62999999999', 0); // changed after the batch was made
    $this->actingAs($this->finance)->get($this->admin("/agents/payouts/{$batch->id}/csv"))->assertSessionHasErrors(['batch']);

    $this->actingAs($this->finance)->post($this->admin("/agents/payouts/{$batch->id}/cancel"))->assertSessionHas('status');
    expect(app(AgentCommission::class)->balance($this->ayanda->id))->toBe(['pending' => 0, 'available' => 30000, 'paid_out' => 0]);
    $this->actingAs($this->finance)->post($this->admin("/agents/payouts/{$batch->id}/cancel"))->assertSessionHasErrors(['batch' => 'Only a batch that hasn’t been paid can be cancelled.']);
});

it('is for finance (and the super admin) only', function () {
    $this->actingAs($this->finance)->get($this->admin('/agents/payouts'))->assertInertia(fn (Assert $p) => $p->component('Agents::Admin/Payouts')->where('minimum', 15000)->has('preview.included', 1));
    $support = $this->staffWithRole('support', 'support@wurk.co.za');
    $this->actingAs($support)->get($this->admin('/agents/payouts'))->assertForbidden();
    $this->actingAs($support)->post($this->admin('/agents/payouts'))->assertForbidden();
});
