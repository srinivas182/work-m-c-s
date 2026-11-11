<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-20 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon');
    $pay = fn (string $purpose, string $status, int $excl, string $paidAt) => DB::table('payments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $glow->id,
        'purpose' => $purpose, 'reference' => Str::random(20), 'gateway' => 'yoco', 'status' => $status, 'currency' => 'ZAR', 'amount_excl_cents' => $excl,
        'vat_cents' => intdiv($excl * 15, 100), 'total_cents' => $excl + intdiv($excl * 15, 100), 'vat_bp' => 1500, 'details' => '{}',
        'paid_at' => $status === 'failed' ? null : CarbonImmutable::parse($paidAt), 'created_at' => CarbonImmutable::parse($paidAt), 'updated_at' => now()]);
    $pay('subscription', 'succeeded', 29900, '2027-03-02');
    $pay('credits', 'succeeded', 10000, '2027-03-05');
    $pay('subscription', 'refunded', 5000, '2027-03-06');
    $pay('subscription', 'charged_back', 8000, '2027-03-07');
    $pay('subscription', 'failed', 29900, '2027-03-08');
    $pay('subscription', 'succeeded', 29900, '2027-02-22');                          // the period before (this month so far = 20 days)
    $invoice = (string) Str::ulid();
    DB::table('invoices')->insert(['id' => $invoice, 'number' => 'INV-1', 'tenant_id' => $glow->id, 'issued_at' => now(), 'issuer' => '{}', 'customer' => '{}', 'currency' => 'ZAR',
        'vat_bp' => 1500, 'excl_cents' => 5000, 'vat_cents' => 750, 'total_cents' => 5750, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('credit_notes')->insert(['id' => (string) Str::ulid(), 'number' => 'CN-1', 'invoice_id' => $invoice, 'tenant_id' => $glow->id, 'issued_at' => CarbonImmutable::parse('2027-03-10'),
        'reason' => 'Refund', 'excl_cents' => 5000, 'vat_cents' => 750, 'total_cents' => 5750, 'refund' => 'card', 'created_at' => now(), 'updated_at' => now()]);
    $ledger = fn (string $kind, int $cents, string $available) => DB::table('agent_ledger')->insert(['id' => (string) Str::ulid(), 'agent_id' => (string) Str::ulid(), 'kind' => $kind,
        'cents' => $cents, 'available_at' => CarbonImmutable::parse($available), 'reference' => Str::random(8), 'created_at' => CarbonImmutable::parse('2027-03-11')]);
    $ledger('earned', 1000, '2027-03-15');   // payable
    $ledger('earned', 500, '2027-04-30');    // still in the refund window
    $ledger('reversed', -200, '2027-03-11');
    $ledger('paid_out', -300, '2027-03-11');
    $ctx = app(TenantContext::class);
    $ctx->run($glow, function () use ($ctx) {
        foreach ([['sms', 3000], ['whatsapp', 2000]] as [$channel, $milli]) {
            DB::connection($ctx->connectionName())->table('credit_ledger')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $ctx->id(), 'type' => 'debit', 'amount_milli' => -$milli,
                'balance_after_milli' => 0, 'idempotency_key' => Str::random(20), 'channel' => $channel, 'description' => 'Test', 'created_at' => CarbonImmutable::parse('2027-03-05 09:00')]);
        }
    });
    $this->artisan('credits:rollup-usage', ['--since' => '2027-03-01']);
    $this->artisan('credits:rollup-usage', ['--since' => '2027-03-01']);              // again: replaced, not doubled
    $sections = collect($this->actingAs($this->staffWithRole('finance', 'finance@wurk.co.za'))->get($this->admin('/metrics'))->assertOk()->viewData('page')['props']['sections']);
    $this->section = fn (string $key) => $sections->firstWhere('key', $key);
});

it('shows money received, refunds, chargebacks, net revenue, VAT and failed payments — by purpose', function () {
    $r = ($this->section)('revenue');
    // VAT collected: R44.85 + R15.00 + R7.50 + R12.00 = R79.35.
    expect(array_column($r['metrics'], 'value', 'label'))->toBe(['Received (excl. VAT)' => 52900, 'Refunds' => 5000, 'Chargebacks' => 8000, 'Net revenue' => 39900,
        'VAT collected' => 7935, 'Failed payments' => 1])
        ->and($r['metrics'][3]['previous'])->toBe(29900)
        ->and($r['rows']['data'])->toContain(['Subscriptions', '3', 'R429.00'])->toContain(['Credits', '1', 'R100.00']);
});

it('shows agent commission and platform-wide credit use', function () {
    expect(array_column(($this->section)('agents')['metrics'], 'value', 'label'))->toBe(['Earned' => 1500, 'Reversed' => 200, 'Paid out' => 300, 'Payable now' => 500, 'Pending (refund window)' => 500])
        ->and(($this->section)('credits')['metrics'][0]['value'])->toBe(5)
        ->and(($this->section)('credits')['rows']['data'])->toBe([['SMS', '3'], ['Whatsapp', '2']]);
});
