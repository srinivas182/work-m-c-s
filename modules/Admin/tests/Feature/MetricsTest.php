<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantProvisioner;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-03-01 08:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $make = fn (string $key, int $monthly, int $yearly) => app(PackageCatalog::class)->create([
        'key' => $key, 'name' => ucfirst($key), 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => $monthly, 'yearly_price_cents' => $yearly, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 0,
        'referral_max_commission_bp' => 0, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $this->starter = $make('starter', 29900, 299000);  // R299/month excl. VAT
    $this->pro = $make('pro', 99900, 999000);          // R9 990/year → R832.50/month
    $sub = function (string $handle, $package, string $period, string $status) {
        $t = $this->createTenant($handle);
        app(Catalog::class)->subscribe($t->id, $package->id, $period, allowTrial: true);
        TenantSubscription::query()->whereKey($t->id)->update(['status' => $status, 'billing_period' => $period]);

        return $t;
    };
    $this->a = $sub('aaa', $this->starter, 'monthly', 'active');
    $this->b = $sub('bbb', $this->pro, 'yearly', 'past_due');
    $this->c = $sub('ccc', $this->starter, 'monthly', 'trial');
    $this->d = $sub('ddd', $this->starter, 'monthly', 'suspended');
    $demo = $sub('eee', $this->pro, 'monthly', 'active');
    app(TenantProvisioner::class)->markDemo($demo->id, true);               // demos aren't customers
    $this->artisan('plans:snapshot-mrr')->expectsOutput('Recorded 4 businesses.');
    $this->artisan('plans:snapshot-mrr')->expectsOutput('Recorded 4 businesses.'); // the same day again: replaced, not doubled
    expect(DB::table('subscription_snapshots')->count())->toBe(4);
    // During March: the trial converts; A is suspended.
    TenantSubscription::query()->whereKey($this->c->id)->update(['status' => 'active']);
    TenantSubscription::query()->whereKey($this->a->id)->update(['status' => 'suspended']);
    $this->travelTo(CarbonImmutable::parse('2027-03-15 08:00', 'Africa/Johannesburg'));
});

it('shows WURK’s MRR, businesses, churn, LTV and trial conversion — compared with the start of the period', function () {
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
    $s = collect($this->actingAs($finance)->get($this->admin('/metrics'))->assertOk()->viewData('page')['props']['sections'])->firstWhere('key', 'subscriptions');
    expect(array_column($s['metrics'], 'value', 'label'))->toMatchArray([
        'MRR (excl. VAT)' => 113150,            // B R832.50 (yearly ÷ 12) + C R299
        'ARR' => 1357800,
        'Paying businesses' => 2,               // B (in grace) and C
        'On trial' => 0, 'New paying' => 1, 'Lost' => 1,     // C converted; A was lost
        'In grace' => 1, 'Suspended' => 2,      // B; A and D
        'Average revenue per business' => 56575,
        'Churn (of paying at the start)' => 50.0, // A of A and B
        'Trial conversion' => 100.0,            // C
        'Lifetime value (estimate)' => 113150,  // R565.75 ÷ 50% a month
    ])->and($s['rows']['data'])->toContain(['Pro', '1', 'R832.50'])->toContain(['Starter', '1', 'R299.00'])
        ->and($s['extra']['data'])->toBe([['ZA', '2', 'R1,131.50']])
        ->and($s['highlights'])->toContain('1 new paying business, 1 lost.');
});

it('is for staff who may see metrics', function () {
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->admin('/metrics'))->assertForbidden();
});
