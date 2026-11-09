<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Identity\Models\Identity;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $roster = app(AgentRoster::class);
    $this->dineo = $roster->create('dineo@example.com', 'Dineo', 'Agent', null, null);
    $this->ezra = $roster->create('ezra@example.com', 'Ezra', 'Agent', $this->dineo->id, null);
    $this->fezi = $roster->create('fezi@example.com', 'Fezi', 'Agent', $this->ezra->id, null);
    $roster->update($this->fezi, 'suspended', null);
    $this->ayanda = $roster->create('ayanda@example.com', 'Ayanda', 'Agent', null, null);
    foreach (['ezra-one', 'ezra-two'] as $h) {
        app(AgentAttribution::class)->set($this->createTenant($h)->id, $this->ezra->id); // Ezra brought 2 businesses
    }
    $line = fn ($agent, int $cents, string $kind, $created, int $level = 0) => DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $agent->id,
        'tenant_id' => $this->glow->id, 'payment_id' => (string) Str::ulid(), 'level' => $level, 'kind' => $kind, 'cents' => $cents, 'bp' => 1000,
        'available_at' => $created->copy()->addDays(30), 'reference' => $kind === 'earned' ? 'payment' : 'credit:1', 'created_at' => $created]);
    $line($this->dineo, 3000, 'earned', now()->startOfMonth()->addHour());
    $line($this->dineo, -1000, 'reversed', now()->startOfMonth()->addHours(2));
    $line($this->dineo, 500, 'earned', now()->subMonths(2), 1);
    $line($this->ayanda, 9900, 'earned', now()->startOfMonth()->addHour()); // someone else's
    foreach ([['B1', 'paid', 'paid', $this->dineo], ['B2', 'cancelled', 'ready', $this->dineo], ['B3', 'paid', 'bounced', $this->dineo], ['B4', 'paid', 'paid', $this->ayanda]] as [$ref, $bs, $is, $who]) {
        $b = (string) Str::ulid();
        DB::table('agent_payout_batches')->insert(['id' => $b, 'reference' => 'WURK-AG-'.$ref, 'status' => $bs, 'count' => 1, 'total_cents' => 2000, 'paid_at' => $bs === 'paid' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agent_payout_items')->insert(['id' => (string) Str::ulid(), 'batch_id' => $b, 'agent_id' => $who->id, 'cents' => 2000, 'holder' => 'X', 'bank' => 'FNB',
            'branch_code' => '250655', 'account_type' => 'cheque', 'account_last4' => '6789', 'status' => $is, 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->me = Identity::query()->findOrFail($this->dineo->identity_id);
    $this->area = fn (string $p) => 'http://'.config('wurk.domains.app').'/agent'.$p;
});

it('shows the agent’s own earnings for a period, and the same as CSV', function () {
    $this->actingAs($this->me)->get(($this->area)('/earnings'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Earnings')
        ->has('lines', 2)->where('lines.0.kind', 'reversed')->where('lines.0.cents', -1000)->where('lines.1.business', 'Glow Salon')->where('lines.1.pending', true));
    $this->actingAs($this->me)->get(($this->area)('/earnings?period=all'))->assertInertia(fn (Assert $p) => $p->has('lines', 3)); // Ayanda's never
    $csv = $this->actingAs($this->me)->get(($this->area)('/earnings?period=all&format=csv'))->assertOk()->getContent();
    $rows = array_map('str_getcsv', array_values(array_filter(explode("\n", (string) $csv))));
    expect($rows[0])->toBe(['Date', 'Business', 'Level', 'Type', 'Amount', 'Payable from'])->and(count($rows))->toBe(4)
        ->and($rows[2][1])->toBe('Glow Salon')->and($rows[3])->toMatchArray([2 => 'Level 1', 3 => 'Earned', 4 => '5.00']);
});

it('shows the team by level — counts only — and the agent’s own payouts', function () {
    $this->actingAs($this->me)->get(($this->area)('/team'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Team')
        ->where('team', [['level' => 1, 'name' => 'Ezra Agent', 'code' => $this->ezra->code, 'status' => 'active', 'businesses' => 2],
            ['level' => 2, 'name' => 'Fezi Agent', 'code' => $this->fezi->code, 'status' => 'suspended', 'businesses' => 0]])); // no earnings fields at all
    $this->actingAs($this->me)->get(($this->area)('/payouts'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Payouts')
        ->where('payouts', fn ($ps) => collect($ps)->pluck('status', 'reference')->sortKeys()->all() === ['WURK-AG-B1' => 'paid', 'WURK-AG-B3' => 'bounced'])); // not cancelled, not Ayanda's
});

it('gives the agent a marketing kit: link, QR code and a WhatsApp message — and every page is for agents only', function () {
    // (Outside a request, route() adds the test APP_URL's port; the page builds links from the request — so compare with the page's own.)
    $kit = $this->actingAs($this->me)->get(($this->area)('/kit'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Kit'))->viewData('page')['props'];
    expect($kit['link'])->toBe('http://'.config('wurk.domains.root').'/a/'.$this->dineo->code)
        ->and($kit['whatsapp'])->toStartWith('https://wa.me/?text=')->and(rawurldecode(substr($kit['whatsapp'], 20)))->toContain($kit['link']);
    expect($this->actingAs($this->me)->get(($this->area)('/qr.svg'))->assertOk()->getContent())->toContain('<svg');
    $stranger = $this->person(['phone' => '+27829999999', 'email' => 'x@example.com']);
    foreach (['/earnings', '/team', '/payouts', '/kit', '/qr.svg'] as $page) {
        $this->actingAs($stranger)->get(($this->area)($page))->assertNotFound();
    }
});
