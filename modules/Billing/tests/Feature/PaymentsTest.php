<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Events\PaymentFailed;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Models\PaymentMethod;
use Wurk\Billing\Support\Payments;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: false);
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
});

function paystackVerified(int $amount, string $status = 'success', string $signature = 'SIG_card1'): array
{
    return ['status' => true, 'data' => ['id' => random_int(1, 99999), 'status' => $status, 'amount' => $amount, 'currency' => 'ZAR',
        'authorization' => ['authorization_code' => 'AUTH_'.$signature, 'reusable' => true, 'signature' => $signature, 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030']]];
}

function balanceOf($test): int
{
    return app(TenantContext::class)->run($test->glow, fn () => app(CreditWallet::class)->balance()['balance_milli']);
}

it('adds 15% VAT, starts the subscription only after Paystack confirms, and saves the card', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']], 200),
        'api.paystack.co/transaction/verify/*' => Http::response(paystackVerified(34385), 200),
    ]);

    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertRedirect(route('tenant.billing', ['tenant' => 'glow-salon']));
    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/pay'))->assertRedirect('https://checkout.paystack.com/x');

    $payment = Payment::query()->firstOrFail();
    expect($payment->only(['amount_excl_cents', 'vat_cents', 'total_cents', 'status']))->toBe(['amount_excl_cents' => 29900, 'vat_cents' => 4485, 'total_cents' => 34385, 'status' => 'pending'])
        ->and(TenantSubscription::query()->find($this->glow->id)->status)->toBe('pending_payment');

    $this->actingAs($this->owner)->get($this->app('/glow-salon/settings/billing/return?reference='.$payment->reference))->assertRedirect()->assertSessionHas('status');

    $sub = TenantSubscription::query()->find($this->glow->id);
    expect($sub->status)->toBe('active')->and($sub->current_period_end->isSameDay(now()->addMonth()))->toBeTrue()
        ->and(PaymentMethod::query()->value('last4'))->toBe('4081')
        ->and(balanceOf($this))->toBe(100000);
    $this->actingAs($this->owner)->get($this->app('/glow-salon'))->assertOk();
});

it('refuses a confirmation whose amount does not match', function () {
    Http::fake(['api.paystack.co/transaction/verify/*' => Http::response(paystackVerified(100), 200)]);
    Http::fake(['api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/m']], 200)]);
    $payment = app(Payments::class)->checkout($this->glow->id, 'subscription', 29900, [], 'https://x')['payment'];

    expect(app(Payments::class)->confirm($payment->reference)->status)->toBe('failed')
        ->and(TenantSubscription::query()->find($this->glow->id)->status)->toBe('pending_payment');
});

it('handles each signed webhook once and ignores forged ones', function () {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/w']], 200),
        'api.paystack.co/transaction/verify/*' => Http::response(paystackVerified(34385), 200),
    ]);
    $payment = app(Payments::class)->checkout($this->glow->id, 'credits', 29900, ['credits' => 500], 'https://x')['payment'];
    $body = (string) json_encode(['event' => 'charge.success', 'data' => ['id' => 777, 'reference' => $payment->reference]]);
    $url = $this->app('/webhooks/billing/paystack');
    $post = fn (string $sig) => $this->call('POST', $url, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYSTACK_SIGNATURE' => $sig], $body);

    $post('forged')->assertStatus(400);
    expect(balanceOf($this))->toBe(0);

    $post(hash_hmac('sha512', $body, 'sk_test'))->assertOk();
    $post(hash_hmac('sha512', $body, 'sk_test'))->assertOk(); // replay
    expect(balanceOf($this))->toBe(500000)
        ->and(Payment::query()->value('status'))->toBe('succeeded');
    Http::assertSentCount(2); // checkout + one verify; the replay never reached Paystack
});

it('renews on the saved card, never twice, and marks failures past due', function () {
    Event::fake([PaymentFailed::class]);
    PaymentMethod::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'gateway' => 'paystack', 'authorization' => 'AUTH_1', 'signature' => 'SIG_1', 'brand' => 'Visa', 'last4' => '4081', 'expires' => '12/2030']);
    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => PaymentMethod::query()->value('id')]);
    app(Catalog::class)->markPaid($this->glow->id, now()->subMonth(), now()->addMinutes(30));

    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::sequence()
        ->push(paystackVerified(34385), 200)
        ->push(['status' => true, 'data' => ['status' => 'failed', 'gateway_response' => 'Insufficient Funds', 'amount' => 34385, 'currency' => 'ZAR']], 200)]);

    $this->artisan('billing:renew')->assertSuccessful();
    $this->artisan('billing:renew')->assertSuccessful(); // not due any more
    expect(Payment::query()->where('status', 'succeeded')->count())->toBe(1)
        ->and(TenantSubscription::query()->find($this->glow->id)->current_period_end->gt(now()->addWeeks(3)))->toBeTrue();

    $this->travelTo(TenantSubscription::query()->find($this->glow->id)->current_period_end->subMinutes(10));
    $this->artisan('billing:renew')->assertSuccessful();
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('past_due');
    Event::assertDispatched(PaymentFailed::class);
});

it('sells credit packs on the saved card, and refunds the R1 card check', function () {
    Http::fake([
        'api.paystack.co/transaction/charge_authorization' => Http::response(paystackVerified(11500, signature: 'SIG_1'), 200),
        'api.paystack.co/transaction/verify/*' => Http::response(paystackVerified(115, signature: 'SIG_new'), 200),
        'api.paystack.co/refund' => Http::response(['status' => true], 200),
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/c']], 200),
    ]);
    app(Catalog::class)->markPaid($this->glow->id, now(), now()->addMonth());
    PaymentMethod::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'gateway' => 'paystack', 'authorization' => 'AUTH_1', 'signature' => 'SIG_1', 'brand' => 'Visa', 'last4' => '4081', 'expires' => '12/2030']);
    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => PaymentMethod::query()->value('id')]);
    $pack = CreditPack::query()->create(['name' => 'Small', 'credits' => 100, 'price_cents' => 10000]);

    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/credits'), ['pack_id' => $pack->id, 'saved_card' => true])->assertSessionHas('status', '100 credits added.');
    expect(balanceOf($this))->toBe(100000);

    $this->actingAs($this->owner)->post($this->app('/glow-salon/settings/billing/card'))->assertRedirect('https://checkout.paystack.com/c');
    $check = Payment::query()->where('purpose', 'card_check')->firstOrFail();
    expect($check->total_cents)->toBe(115);
    $this->actingAs($this->owner)->get($this->app('/glow-salon/settings/billing/return?reference='.$check->reference));

    expect($check->fresh()->status)->toBe('refunded')
        ->and(PaymentMethod::query()->count())->toBe(2)
        ->and(BillingAccount::query()->value('default_method_id'))->toBe(PaymentMethod::query()->where('signature', 'SIG_new')->value('id'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/refund') && $r['transaction'] === $check->reference);
});

it('keeps billing to people allowed to manage it', function () {
    $desk = $this->person(['email' => 'desk@glow.co.za', 'phone' => null]);
    $this->member($desk, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($desk->id, 'Desk', 'staff'));

    $this->actingAs($desk)->get($this->app('/glow-salon/settings/billing'))->assertNotFound();
    $this->actingAs($this->staffWithRole('support'))->get($this->admin('/credits/packs'))->assertForbidden();
});
