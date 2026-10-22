<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Contracts\ServiceStatus;
use Wurk\Billing\Mail\DunningNoticeMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Models\PaymentMethod;
use Wurk\Billing\Support\Proration;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function pkg(string $key, int $monthly, int $yearly): string
{
    return app(PackageCatalog::class)->create([
        'key' => $key, 'name' => ucfirst($key), 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => $monthly, 'yearly_price_cents' => $yearly, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null)->id;
}

function charged(int $amount, string $status = 'success'): array
{
    return ['status' => true, 'data' => ['id' => random_int(1, 999999), 'status' => $status, 'amount' => $amount, 'currency' => 'ZAR', 'gateway_response' => $status === 'success' ? 'Approved' : 'Insufficient Funds',
        'authorization' => ['authorization_code' => 'AUTH_1', 'reusable' => true, 'signature' => 'SIG_1', 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030']]];
}

beforeEach(function () {
    Mail::fake();
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->starter = pkg('starter', 29900, 299000);
    $this->growth = pkg('growth', 59900, 599000);
    app(Catalog::class)->subscribe($this->glow->id, $this->starter, 'monthly', allowTrial: false);
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    PaymentMethod::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'gateway' => 'paystack', 'authorization' => 'AUTH_1', 'signature' => 'SIG_1', 'brand' => 'Visa', 'last4' => '4081', 'expires' => '12/2030']);
    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => PaymentMethod::query()->value('id')]);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
});

it('prorates to the cent (PRD §11.3 worked examples)', function () {
    $now = CarbonImmutable::parse('2026-12-16T00:00:00+00:00'); // explicit UTC: the app runs on SA time
    $current = ['period' => 'monthly', 'period_cents' => 29900, 'current_period_start' => '2026-12-01T00:00:00+00:00', 'current_period_end' => '2026-12-31T00:00:00+00:00'];

    // Halfway through: (599.00 − 299.00) × ½ = R150.00
    expect(Proration::quote($current, 'monthly', 59900, $now))->toBe(['kind' => 'upgrade', 'charge_cents' => 15000, 'restart_period' => false])
        // Monthly → yearly: R2 990.00 − unused half month (R149.50) = R2 840.50, new year starts now
        ->and(Proration::quote($current, 'yearly', 299000, $now))->toBe(['kind' => 'upgrade', 'charge_cents' => 284050, 'restart_period' => true])
        ->and(Proration::quote($current, 'monthly', 19900, $now)['kind'])->toBe('scheduled')
        ->and(Proration::quote([...$current, 'period' => 'yearly', 'period_cents' => 299000], 'monthly', 29900, $now)['kind'])->toBe('scheduled');
});

it('upgrades now on the saved card for the prorated difference, and invoices it', function () {
    app(Catalog::class)->markPaid($this->glow->id, now()->subDays(15), now()->addDays(15));
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(charged(17250), 200)]); // R150.00 + VAT

    $this->actingAs($this->owner)->getJson($this->app("/glow-salon/settings/billing/change/quote?package_id={$this->growth}&period=monthly"))->assertJson(['kind' => 'upgrade', 'charge_cents' => 15000]);
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/change'), ['package_id' => $this->growth, 'period' => 'monthly'])->assertSessionHas('status');

    $sub = TenantSubscription::query()->find($this->glow->id);
    expect($sub->package_id)->toBe($this->growth)->and($sub->current_period_end->isSameDay(now()->addDays(15)))->toBeTrue()
        ->and(Invoice::query()->latest('issued_at')->first()->lines()->value('description'))->toContain('upgrade to Growth');
});

it('applies a scheduled downgrade at renewal', function () {
    TenantSubscription::query()->whereKey($this->glow->id)->update(['package_id' => $this->growth, 'package_version_id' => Package::query()->find($this->growth)->current_version_id]);
    app(Catalog::class)->markPaid($this->glow->id, now()->subMonth(), now()->addMinutes(30));

    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/change'), ['package_id' => $this->starter, 'period' => 'monthly'])->assertSessionHas('status');
    expect(TenantSubscription::query()->find($this->glow->id)->package_id)->toBe($this->growth);

    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::response(charged(34385), 200)]);
    $this->artisan('billing:renew')->assertSuccessful();
    expect(TenantSubscription::query()->find($this->glow->id)->package_id)->toBe($this->starter)
        ->and(Payment::query()->where('status', 'succeeded')->value('total_cents'))->toBe(34385);
});

it('runs the dunning schedule: notice, grace banner, retries, billing-only, suspension, and restores on payment', function () {
    $this->travelTo('2026-12-01 09:00');
    app(Catalog::class)->markPaid($this->glow->id, now()->subMonth(), now()->addMinutes(30));
    // One fake whose answer we switch: stacked Http::fake() calls keep the first match.
    $bank = 'failed';
    Http::fake(['api.paystack.co/transaction/charge_authorization' => function () use (&$bank) {
        return Http::response(charged(34385, $bank === 'failed' ? 'failed' : 'success'), 200);
    }]);

    $this->artisan('billing:renew');
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('past_due');
    Mail::assertSent(DunningNoticeMail::class, fn ($m) => $m->kind === 'failed');

    // Day 1: full service with a banner.
    $this->travel(1)->days();
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('banner.tone', 'danger'));

    // Day 3: one retry, even if the job runs twice.
    $this->travelTo('2026-12-04 09:00');
    $this->artisan('billing:dunning');
    $this->artisan('billing:dunning');
    expect(Payment::query()->where('details->dunning_day', 3)->count())->toBe(1);

    // Day 8: only Billing.
    $this->travelTo('2026-12-09 09:00');
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertRedirect(route('tenant.billing', ['tenant' => 'glow-salon']));

    // Day 15: suspended, owner told, public site to show "unavailable" (Sprint 5).
    $this->travelTo('2026-12-16 09:00');
    $this->artisan('billing:dunning');
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('suspended')
        ->and(app(ServiceStatus::class)->suspendedForNonPayment($this->glow->id))->toBeTrue();
    Mail::assertSent(DunningNoticeMail::class, fn ($m) => $m->kind === 'suspended');
    $this->actingAs($this->owner)->get($this->app('/glow-salon/settings/billing'))->assertOk();

    // Paying restores everything at once.
    $bank = 'success';
    $this->flushSession(); // staff use their own session (the owner's would be idle after 15 days)
    $this->actingAs($this->staffWithRole('finance'))->post($this->admin("/businesses/{$this->glow->id}/billing/retry"))->assertSessionHas('status');
    $sub = TenantSubscription::query()->find($this->glow->id);
    expect($sub->status)->toBe('active')->and($sub->past_due_since)->toBeNull()
        ->and(app(ServiceStatus::class)->suspendedForNonPayment($this->glow->id))->toBeFalse();
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertOk()->assertInertia(fn (Assert $p) => $p->where('banner', null));
});

it('lists businesses in dunning for staff', function () {
    app(Catalog::class)->markPaid($this->glow->id, now()->subMonth(), now()->addMinutes(30));
    app(Catalog::class)->markPastDue($this->glow->id);

    $this->actingAs($this->staffWithRole('support'))->get($this->admin('/billing/failed'))
        ->assertInertia(fn (Assert $p) => $p->component('Billing::Admin/FailedPayments')->where('rows.0.name', 'Glow Salon')->where('rows.0.stage', 'grace'));
});
