<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-04-10 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    [$a, $b, $c, $d, $e] = array_map(fn () => (string) Str::ulid(), range(1, 5));
    $snap = function (string $day, array $mrr) {
        foreach ($mrr as $tenant => $cents) {
            DB::table('subscription_snapshots')->insert(['day' => $day, 'tenant_id' => $tenant, 'status' => $cents > 0 ? 'active' : 'suspended', 'package_id' => null,
                'period' => 'monthly', 'mrr_cents' => $cents, 'country' => 'ZA']);
        }
    };
    $snap('2027-02-28', [$a => 10000, $b => 20000, $c => 15000, $d => 5000]);   // February's cohort: A–D
    $snap('2027-03-01', [$a => 10000, $b => 20000, $c => 15000, $d => 0]);       // opening (D suspended)
    $snap('2027-04-01', [$a => 12000, $b => 15000, $c => 0, $d => 8000, $e => 9000]); // closing
    $this->finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
});

it('shows how MRR moved — and it adds up — and how each month’s new paying businesses stayed', function () {
    $m = collect($this->actingAs($this->finance)->get($this->admin('/metrics?period=last-month'))->assertOk()->viewData('page')['props']['sections'])->firstWhere('key', 'movement');
    expect($m['rows']['data'])->toBe([['Opening', 'R450.00'], ['+ New', 'R90.00'], ['+ Expansion', 'R20.00'], ['+ Reactivation', 'R80.00'],
        ['− Contraction', '−R50.00'], ['− Churn', '−R150.00'], ['Closing', 'R440.00']])
        ->and($m['metrics'][2]['value'])->toBe(-1000)                                       // net new MRR
        ->and(array_slice(collect($m['extra']['data'])->firstWhere(0, 'Feb 2027'), 0, 4))->toBe(['Feb 2027', '4', '100%', '75%']); // C churned by end-March
});

it('downloads the metrics as CSV', function () {
    $csv = (string) $this->actingAs($this->finance)->get($this->admin('/metrics/export.csv?period=last-month'))->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->getContent();
    expect($csv)->toStartWith("Section,Figure,\"This period\",\"Previous period\"\n")->toContain('"MRR movement","Opening MRR",R450.00')->toContain('"− Churn",−R150.00');
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->admin('/metrics/export.csv'))->assertForbidden();
});
