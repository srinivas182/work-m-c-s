<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-04-01 10:00', 'Africa/Johannesburg')); // "last month" = March (31 days)
    $this->createShards(1);
    $this->starter = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 0,
        'referral_max_commission_bp' => 0, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    $sub = function (string $handle, string $status) {
        $t = $this->createTenant($handle, name: ucfirst($handle).' Salon');
        app(Catalog::class)->subscribe($t->id, $this->starter->id, 'monthly', allowTrial: true);
        TenantSubscription::query()->whereKey($t->id)->update(['status' => $status, 'billing_period' => 'monthly']);

        return $t;
    };
    $this->a = $sub('alpha', 'active');
    $this->b = $sub('bravo', 'trial');
    DB::table('payments')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $this->a->id, 'purpose' => 'subscription', 'reference' => Str::random(20), 'gateway' => 'yoco',
        'status' => 'succeeded', 'currency' => 'ZAR', 'amount_excl_cents' => 29900, 'vat_cents' => 4485, 'total_cents' => 34385, 'vat_bp' => 1500, 'details' => '{}',
        'paid_at' => CarbonImmutable::parse('2027-03-03'), 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$this->a->id, 'sms', 100], [$this->b->id, 'sms', 20], [$this->b->id, 'whatsapp', 10]] as [$t, $ch, $n]) {
        DB::table('credit_usage_daily')->insert(['day' => '2027-03-10', 'tenant_id' => $t, 'channel' => $ch, 'used_milli' => $n * 1000, 'messages' => $n]);
    }
    $this->finance = $this->staffWithRole('finance', 'finance@wurk.co.za');
});

it('works out profit per package from real costs — and says which costs aren’t set', function () {
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->put($this->admin('/profitability/costs'), ['running' => '50'])->assertForbidden();
    $this->actingAs($this->finance)->put($this->admin('/profitability/costs'), ['provider' => ['sms' => '250', 'whatsapp' => '', 'email' => ''], 'running' => '50'])
        ->assertSessionHas('status', 'Saved.');
    $profit = collect($this->actingAs($this->finance)->get($this->admin('/metrics?period=last-month'))->viewData('page')['props']['sections'])->firstWhere('key', 'profit');
    $running = (int) round(5000 * 31 / 30.4375);                                                       // R50 a month, for 31 days
    expect(array_column($profit['metrics'], 'value', 'label'))->toBe(['Revenue (excl. VAT)' => 29900, 'Provider costs' => 3000, 'Running costs' => 2 * $running,
        'Profit' => 29900 - 3000 - 2 * $running])                                                      // 120 SMS at R250 per 1 000
        ->and($profit['rows']['data'])->toBe([['Starter', '2', 'R299.00', 'R30.00', 'R101.84', 'R167.16', '56%']])
        ->and($profit['extra']['data'])->toBe([['Bravo Salon', 'Starter', 'R0.00', 'R5.00']])           // the trial's messages cost more than it paid
        ->and($profit['note'])->toContain('Provider cost not set for whatsapp');
});

it('shows the costs and a pricing “what if” with today’s paying businesses', function () {
    $this->actingAs($this->finance)->get($this->admin('/profitability'))->assertInertia(fn (Assert $p) => $p->component('Billing::Admin/Profitability')
        ->where('canEdit', true)->where('running', null)                                               // not set yet
        ->where('packages.0', ['id' => $this->starter->id, 'name' => 'Starter', 'paying' => 1, 'mrr_cents' => 29900, 'monthly_cents' => 29900]));
});
