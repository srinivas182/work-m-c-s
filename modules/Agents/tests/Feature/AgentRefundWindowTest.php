<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Agents\Support\AgentCommission;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Billing\Events\PaymentSucceeded;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingSettings;
use Wurk\Billing\Support\Invoices;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->chris = app(AgentRoster::class)->create('chris@example.com', 'Chris', 'Agent', null, null);
    $this->dineo = app(AgentRoster::class)->create('dineo@example.com', 'Dineo', 'Agent', $this->chris->id, null);
    app(AgentAttribution::class)->set($this->glow->id, $this->dineo->id);
    app(AgentCommission::class)->setPercentages([1000, 500]); // Dineo 10%, Chris 5%
    $this->pay = function () {
        $p = Payment::query()->forceCreate(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'purpose' => 'subscription', 'reference' => 'WURK-'.Str::upper((string) Str::ulid()),
            'gateway' => 'paystack', 'status' => 'succeeded', 'currency' => 'ZAR', 'amount_excl_cents' => 30000, 'vat_cents' => 4500, 'total_cents' => 34500, 'vat_bp' => 1500, 'details' => ['package' => 'Starter'], 'paid_at' => now()]);
        event(new PaymentSucceeded($p->id)); // commission, and the invoice

        return $p;
    };
    $this->bal = fn ($a) => app(AgentCommission::class)->balance($a->id);
    $this->credit = fn (Payment $p, ?int $excl) => app(Invoices::class)->credit(Invoice::query()->where('payment_id', $p->id)->sole(), $excl, 'Test', null);
});

it('reverses commission in proportion to refunds within the window — exactly to zero', function () {
    $p = ($this->pay)();
    ($this->credit)($p, 10000); // a third of R300
    expect(($this->bal)($this->dineo)['pending'])->toBe(2000)->and(($this->bal)($this->chris)['pending'])->toBe(1000);
    ($this->credit)($p, null);  // the rest
    expect(($this->bal)($this->dineo))->toBe(['pending' => 0, 'available' => 0, 'paid_out' => 0])->and(($this->bal)($this->chris)['pending'])->toBe(0);
});

it('refuses refunds once WURK’s window has closed, leaving commission untouched; 0 days = no refunds and commission payable at once', function () {
    $p = ($this->pay)();
    $this->travel(31)->days();
    expect(fn () => ($this->credit)($p, 10000))->toThrow(ValidationException::class, 'Refunds for this invoice closed on');
    expect(($this->bal)($this->dineo)['available'])->toBe(3000);

    app(BillingSettings::class)->setRefundDays(0, null);
    $q = ($this->pay)();
    expect(($this->bal)($this->dineo)['available'])->toBe(6000) // payable at once
        ->and(fn () => ($this->credit)($q, 10000))->toThrow(ValidationException::class, 'WURK doesn’t give refunds.');
});

it('reverses a chargeback in full even after payout — the agent owes WURK, flagged after 90 days', function () {
    $p = ($this->pay)();
    $this->travel(31)->days();
    DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => $this->dineo->id, 'tenant_id' => null, 'payment_id' => null, 'level' => null,
        'kind' => 'paid_out', 'cents' => -3000, 'bp' => null, 'available_at' => now(), 'reference' => 'batch-test', 'created_at' => now()]); // paid out (14.5 builds payouts)
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->post($this->admin("/billing/payments/{$p->id}/chargeback"))->assertSessionHas('status', 'Chargeback recorded. Any agent commission on it has been reversed.');
    $this->actingAs($admin)->post($this->admin("/billing/payments/{$p->id}/chargeback"))->assertSessionHasErrors(['payment' => 'Only a paid payment can be charged back.']);
    expect(($this->bal)($this->dineo)['available'])->toBe(-3000)->and(($this->bal)($this->chris)['available'])->toBe(0);
    // Dineo's row, by name (both agents were created in the same second, so the list's order isn't fixed).
    $dineo = fn () => collect($this->actingAs($admin)->get($this->admin('/agents'))->viewData('page')['props']['agents'])->firstWhere('name', 'Dineo Agent');
    expect($dineo())->toMatchArray(['owes_flag' => false, 'owes_since' => now()->toDateString()]);
    $this->travel(91)->days(); // (staff sessions expire long before this, so check the rule the list uses directly)
    expect(app(AgentCommission::class)->owesSince($this->dineo->id)?->lt(now()->subDays(90)))->toBeTrue();
});

it('lets the super admin set WURK’s refund window in Billing settings', function () {
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->get($this->admin('/billing/settings'))->assertInertia(fn (Assert $pg) => $pg->where('refundDays', 30));
    $s = app(BillingSettings::class);
    $this->actingAs($admin)->put($this->admin('/billing/settings'), ['name' => 'WURK (Pty) Ltd', 'address' => 'Cape Town', 'vat_number' => '4123456789', 'registration' => '',
        'retry_days' => [3, 5, 7], 'restrict_from_day' => 8, 'suspend_on_day' => 15, 'refund_days' => 14])->assertSessionHasNoErrors();
    expect(app(BillingSettings::class)->refundDays())->toBe(14)->and(app(AgentCommission::class)->refundDays())->toBe(14);
});
