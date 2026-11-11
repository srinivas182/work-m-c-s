<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-20 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $starter = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 0,
        'referral_max_commission_bp' => 0, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $paying = function (string $handle, int $lastSeenDaysAgo) use ($starter) {
        $t = $this->createTenant($handle, name: ucfirst($handle).' Salon');
        app(Catalog::class)->subscribe($t->id, $starter->id, 'monthly', allowTrial: false);
        TenantSubscription::query()->whereKey($t->id)->update(['status' => 'active', 'billing_period' => 'monthly']);
        $owner = $this->person(['phone' => '+2783'.random_int(1000000, 9999999), 'email' => $handle.'@example.com']);
        $this->member($owner, $t);
        DB::table('identities')->where('id', $owner->id)->update(['last_login_at' => now()->subDays($lastSeenDaysAgo)]);
        DB::table('payments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t->id, 'purpose' => 'subscription', 'reference' => Str::random(20), 'gateway' => 'yoco',
            'status' => 'succeeded', 'currency' => 'ZAR', 'amount_excl_cents' => 29900, 'vat_cents' => 4485, 'total_cents' => 34385, 'vat_bp' => 1500, 'details' => '{}',
            'paid_at' => now()->subDays(3), 'created_at' => now(), 'updated_at' => now()]);

        return $t;
    };
    $paying('quiet', 40);   // no one has signed in for 40 days
    $paying('busy', 5);
    $sections = collect($this->actingAs($this->staffWithRole('finance', 'finance@wurk.co.za'))->get($this->admin('/metrics'))->viewData('page')['props']['sections']);
    $this->section = fn (string $key) => $sections->firstWhere('key', $key);
});

it('flags paying businesses no one has signed in to for 30+ days', function () {
    $h = ($this->section)('health');
    expect(array_column($h['metrics'], 'value'))->toBe([1, 29900])
        ->and($h['rows']['data'])->toBe([['Quiet Salon', 'Starter', 'R299.00', '2027-04-10']]);
});

it('splits net revenue by package and country', function () {
    expect(($this->section)('revenue')['extra']['data'])->toBe([['Starter', 'ZA', 'R598.00']]);
});
