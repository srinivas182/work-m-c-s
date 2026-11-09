<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Agents\Support\AgentCommission;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Models\Payment;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $roster = app(AgentRoster::class);
    $this->ayanda = $roster->create('ayanda@example.com', 'Ayanda', 'Agent', null, null);
    $this->bongi = $roster->create('bongi@example.com', 'Bongi', 'Agent', $this->ayanda->id, null);
    $this->chris = $roster->create('chris@example.com', 'Chris', 'Agent', $this->bongi->id, null);
    $this->dineo = $roster->create('dineo@example.com', 'Dineo', 'Agent', $this->chris->id, null);
    app(AgentAttribution::class)->set($this->glow->id, $this->dineo->id); // Glow Salon is Dineo's business
    $this->pay = function (string $purpose = 'subscription', ?string $tenant = null) {
        $p = Payment::query()->forceCreate(['id' => (string) Str::ulid(), 'tenant_id' => $tenant ?? $this->glow->id, 'purpose' => $purpose, 'reference' => 'WURK-'.Str::upper((string) Str::ulid()),
            'gateway' => 'test', 'status' => 'succeeded', 'currency' => 'ZAR', 'amount_excl_cents' => 30000, 'vat_cents' => 4500, 'total_cents' => 34500, 'vat_bp' => 1500,
            'details' => [], 'paid_at' => now()]);
        event(new PaymentSucceeded($p->id));

        return $p->id;
    };
    $this->bal = fn ($agent) => app(AgentCommission::class)->balance($agent->id);
});

it('earns nothing until the super admin sets percentages', function () {
    ($this->pay)();
    expect(DB::table('agent_ledger')->count())->toBe(0);
});

it('pays the business’s agent and each sponsor up — excluding VAT, once, pending until refunds close', function () {
    app(AgentCommission::class)->setPercentages([1000, 500, 300]); // self 10%, level 1 5%, level 2 3%
    $payment = ($this->pay)();
    event(new PaymentSucceeded($payment)); // the same notice again
    expect(($this->bal)($this->dineo)['pending'])->toBe(3000)      // 10% of R300 (not R345)
        ->and(($this->bal)($this->chris)['pending'])->toBe(1500)
        ->and(($this->bal)($this->bongi)['pending'])->toBe(900)
        ->and(($this->bal)($this->ayanda)['pending'])->toBe(0)     // no level 3 set
        ->and(DB::table('agent_ledger')->count())->toBe(3);
    $this->travel(31)->days();
    expect(($this->bal)($this->dineo))->toBe(['pending' => 0, 'available' => 3000, 'paid_out' => 0]);

    ($this->pay)('credits');                                       // not a subscription
    ($this->pay)('subscription', $this->createTenant('kasi-threads')->id); // a business without an agent
    expect(DB::table('agent_ledger')->count())->toBe(3);
});

it('skips suspended agents without shifting levels, and stops each agent at the end of their own commission period', function () {
    app(AgentCommission::class)->setPercentages([1000, 500, 300]);
    app(AgentRoster::class)->update($this->chris, 'suspended', null);
    app(AgentRoster::class)->update($this->dineo, 'active', 1); // Dineo: 1 month from the business joining
    ($this->pay)();
    expect(($this->bal)($this->chris)['pending'])->toBe(0)->and(($this->bal)($this->bongi)['pending'])->toBe(900)->and(($this->bal)($this->dineo)['pending'])->toBe(3000);
    $this->travel(2)->months();
    ($this->pay)();
    expect(($this->bal)($this->dineo)['available'] + ($this->bal)($this->dineo)['pending'])->toBe(3000) // nothing more for Dineo
        ->and(($this->bal)($this->bongi)['available'] + ($this->bal)($this->bongi)['pending'])->toBe(1800); // Bongi (lifetime) earns again
});

it('lets the super admin set the percentages (logged); finance can look but not change', function () {
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->put($this->admin('/agents/commission'), ['level_bp' => [1000, 500, 300]])->assertSessionHas('status', 'Commission percentages saved. They apply to payments from now on.');
    $this->actingAs($admin)->put($this->admin('/agents/commission'), ['level_bp' => array_fill(0, 9, 100)])->assertSessionHasErrors(['level_bp' => 'Up to 7 levels above the business’s own agent.']);
    $this->actingAs($admin)->get($this->admin('/agents/commission'))->assertInertia(fn (Assert $p) => $p->component('Agents::Admin/Commission')->where('levelBp', [1000, 500, 300])->where('refundDays', 30));
    expect(PlatformAuditEvent::query()->where('action', 'agents.percentages')->count())->toBe(1);
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $this->actingAs($finance)->get($this->admin('/agents/commission'))->assertInertia(fn (Assert $p) => $p->where('canManage', false));
    $this->actingAs($finance)->put($this->admin('/agents/commission'), ['level_bp' => [5000]])->assertForbidden();
});
