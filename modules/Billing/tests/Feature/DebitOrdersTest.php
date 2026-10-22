<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Gateways\FakeDebitOrderProvider;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Mandate;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\SaBanking;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

/** A valid South African ID number (YYMMDD + 6 digits + Luhn check digit). */
function saId(string $first12 = '880101500908'): string
{
    foreach (range(0, 9) as $d) {
        if (SaBanking::validIdNumber($first12.$d)) {
            return $first12.$d;
        }
    }
    throw new RuntimeException('no check digit');
}

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: false);
    app(Catalog::class)->markPaid($this->glow->id, now()->subMonth(), now()->addMinutes(30));
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->form = ['holder_name' => 'Thandi Mokoena', 'id_number' => saId(), 'bank' => 'Capitec', 'branch_code' => '470010', 'account_number' => '1234567890', 'account_type' => 'savings', 'collection_day' => 1];
});

function simulate($test, string $type): void
{
    $test->actingAs($test->owner)->post('http://'.config('wurk.domains.app').'/glow-salon/settings/billing/debit-order/simulate', ['type' => $type])->assertSessionHas('status');
}

it('checks South African ID numbers', function () {
    expect(SaBanking::validIdNumber(saId()))->toBeTrue()
        ->and(SaBanking::validIdNumber('8801015009080'))->toBe(SaBanking::validIdNumber('8801015009080'))
        ->and(SaBanking::validIdNumber('8813015009087'))->toBeFalse() // month 13
        ->and(SaBanking::validIdNumber('12345'))->toBeFalse();

    $wrong = substr(saId(), 0, 12).((int) substr(saId(), 12) + 1) % 10;
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), [...$this->form, 'id_number' => $wrong])->assertSessionHasErrors('id_number');
});

it('keeps bank details encrypted and masked, and switches to debit orders once the bank approves', function () {
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), $this->form)->assertSessionHas('status');

    $mandate = Mandate::query()->firstOrFail();
    expect($mandate->only(['status', 'account_masked', 'max_amount_cents']))->toBe(['status' => 'pending_authentication', 'account_masked' => '••••7890', 'max_amount_cents' => 44850])
        ->and(json_encode(DB::table('mandates')->first()))->not->toContain('1234567890')->not->toContain($this->form['id_number']);

    simulate($this, 'mandate.approved');
    expect($mandate->fresh()->status)->toBe('active')
        ->and(BillingAccount::query()->value('collection_method'))->toBe('debit_order');
});

it('collects renewals by debit order without drifting the billing date, and never twice', function () {
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), $this->form);
    simulate($this, 'mandate.approved');
    $periodEnd = TenantSubscription::query()->find($this->glow->id)->current_period_end;

    $this->artisan('billing:renew')->assertSuccessful();
    $this->artisan('billing:renew')->assertSuccessful();
    expect(Payment::query()->where('gateway', 'debicheck')->count())->toBe(1)
        ->and(Payment::query()->value('status'))->toBe('pending')
        ->and(TenantSubscription::query()->find($this->glow->id)->status)->toBe('active'); // submitted, not failed

    $this->travel(3)->days(); // debit orders settle days later
    simulate($this, 'collection.succeeded');
    $sub = TenantSubscription::query()->find($this->glow->id);
    expect($sub->current_period_start->equalTo($periodEnd))->toBeTrue()
        ->and($sub->current_period_end->equalTo($periodEnd->copy()->addMonth()))->toBeTrue();
});

it('marks the subscription past due when a debit order bounces days later', function () {
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), $this->form);
    simulate($this, 'mandate.approved');
    $this->artisan('billing:renew');

    simulate($this, 'collection.failed');
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('past_due')
        ->and(Payment::query()->value('status'))->toBe('failed');
});

it('accepts each signed provider event once and rejects forged ones', function () {
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), $this->form);
    $mandate = Mandate::query()->firstOrFail();
    $body = (string) json_encode(['id' => 'evt-1', 'type' => 'mandate.approved', 'mandate' => $mandate->provider_mandate_id]);
    $send = fn (string $sig) => $this->call('POST', $this->app('/webhooks/billing/debit-orders'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_FAKE_SIGNATURE' => $sig], $body);

    $send('forged')->assertStatus(400);
    expect($mandate->fresh()->status)->toBe('pending_authentication');
    $send(FakeDebitOrderProvider::sign($body))->assertOk();
    $send(FakeDebitOrderProvider::sign($body))->assertOk();
    expect($mandate->fresh()->status)->toBe('active')
        ->and(DB::table('gateway_events')->where('id', 'fake:evt-1')->count())->toBe(1);
});

it('never offers the stand-in provider in production', function () {
    app()->detectEnvironment(fn () => 'production');
    // "production" also turns off the test-only CSRF bypass; the check here is the provider guard.
    $this->withoutMiddleware(PreventRequestForgery::class);

    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order'), $this->form)->assertSessionHasErrors('debit_order');
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/debit-order/simulate'), ['type' => 'mandate.approved'])->assertNotFound();
    expect(Mandate::query()->count())->toBe(0);
});
