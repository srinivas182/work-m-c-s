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
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $pkg = fn (int $trial) => app(PackageCatalog::class)->create([
        'key' => 'p'.Str::lower((string) Str::ulid()), 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => $trial, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $paid = $pkg(0);
    $trial = $pkg(14);
    $catalog = app(Catalog::class);
    $this->dineo = app(AgentRoster::class)->create('dineo@example.com', 'Dineo', 'Agent', null, null);
    $ayanda = app(AgentRoster::class)->create('ayanda@example.com', 'Ayanda', 'Agent', null, null);
    foreach (['glow-salon' => 'Glow Salon', 'kasi-threads' => 'Kasi Threads', 'lumo' => 'Lumo', 'nala' => 'Nala', 'other' => 'Ayanda’s business'] as $h => $name) {
        $t = $this->createTenant($h, name: $name);
        $catalog->subscribe($t->id, ($h === 'nala' ? $trial : $paid)->id, 'monthly', allowTrial: $h === 'nala');
        app(AgentAttribution::class)->set($t->id, $h === 'other' ? $ayanda->id : $this->dineo->id);
        $this->{str_replace('-', '_', $h)} = $t;
    }
    foreach (['glow_salon', 'kasi_threads', 'lumo'] as $paying) { // active the real way: a paid period
        $catalog->markPaid($this->{$paying}->id, now()->subDays(5), now()->addDays(25));
    }
    $catalog->markPastDue($this->kasi_threads->id);
    $catalog->markPastDue($this->lumo->id);              // suspended only from past due
    $catalog->suspendForNonPayment($this->lumo->id);
    foreach ([[3000, -1], [1500, 10]] as [$cents, $days]) {
        DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $this->dineo->id, 'tenant_id' => $this->glow_salon->id, 'payment_id' => (string) Str::ulid(),
            'level' => 0, 'kind' => 'earned', 'cents' => $cents, 'bp' => 1000, 'available_at' => now()->addDays($days), 'reference' => 'payment', 'created_at' => now()]);
    }
    $this->me = Identity::query()->findOrFail($this->dineo->identity_id);
    $this->area = fn (string $p = '') => 'http://'.config('wurk.domains.app').'/agent'.$p;
});

it('shows the agent their own businesses — at-risk first — and never another agent’s', function () {
    $this->actingAs($this->me)->get(($this->area)('/businesses'))->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Businesses')
        ->has('businesses', 4)
        ->where('businesses.0.name', 'Kasi Threads')->where('businesses.0.status', 'at_risk')
        ->where('businesses', fn ($b) => collect($b)->pluck('status', 'name')->all() === ['Kasi Threads' => 'at_risk', 'Glow Salon' => 'active', 'Lumo' => 'churned', 'Nala' => 'trial'])
        ->where('businesses.1.monthly_cents', 59900)->where('businesses.1.earned_cents', 4500));
});

it('sums up the agent’s position: counts, commission, next payout and what becomes available when', function () {
    $this->actingAs($this->me)->get(($this->area)())->assertInertia(fn (Assert $p) => $p->component('Agents::Agent/Home')
        ->where('businesses', ['total' => 4, 'this_month' => 4, 'active' => 2, 'at_risk' => 1, 'churned' => 1])
        ->where('commission', ['pending' => 1500, 'available' => 3000, 'paid_out' => 0])
        ->where('this_month_cents', 4500)
        ->where('next_payout.state', 'skipped')->where('next_payout.reason', 'No banking details')
        ->where('upcoming', [['date' => now()->addDays(10)->toDateString(), 'cents' => 1500]])
        ->has('months', 12)->where('months.11', ['month' => now()->format('Y-m'), 'cents' => 4500]));
});
