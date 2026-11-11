<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Mail\CreditsMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Models\PaymentMethod;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Events\LowCreditBalance;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

function topUpCharge(int $amount, string $status = 'success'): array
{
    return ['status' => true, 'data' => ['id' => random_int(1, 99999), 'status' => $status, 'amount' => $amount, 'currency' => 'ZAR', 'gateway_response' => $status === 'success' ? 'Approved' : 'Insufficient Funds',
        'authorization' => ['authorization_code' => 'AUTH_SIG_1', 'reusable' => true, 'signature' => 'SIG_1', 'brand' => 'visa', 'last4' => '4081', 'exp_month' => '12', 'exp_year' => '2030']]];
}

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2027-05-10 10:00', 'Africa/Johannesburg'));
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 100, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: false);
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon']);
    PaymentMethod::query()->create(['id' => (string) Str::ulid(), 'tenant_id' => $this->glow->id, 'gateway' => 'paystack', 'authorization' => 'AUTH_SIG_1', 'signature' => 'SIG_1', 'brand' => 'Visa', 'last4' => '4081', 'expires' => '12/2030']);
    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => PaymentMethod::query()->value('id')]);
    $this->pack = CreditPack::query()->create(['id' => (string) Str::ulid(), 'name' => 'Boost', 'credits' => 100, 'price_cents' => 10000, 'active' => true]);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner')); // billing.manage
    $this->low = fn () => event(new LowCreditBalance($this->glow->id, 1000, 20000));
    $this->balance = fn () => app(TenantContext::class)->run($this->glow, fn () => app(CreditWallet::class)->balance()['balance_milli']);
    $this->sent = fn (string $subject) => Mail::assertSent(CreditsMail::class, fn (CreditsMail $m) => $m->hasTo('owner@glow.co.za') && $m->subjectLine === $subject);
});

it('tops up with the saved card when credits run low — within the monthly limit — and emails every time', function () {
    $this->actingAs($this->owner)->put($this->app('/glow-salon/settings/billing/credit-settings'), ['auto_top_up' => true, 'pack_id' => '', 'cap' => '150', 'usage_summary' => true])
        ->assertSessionHasErrors(['pack_id' => 'Choose the pack to buy.']);
    $this->actingAs($this->owner)->put($this->app('/glow-salon/settings/billing/credit-settings'), ['auto_top_up' => true, 'pack_id' => $this->pack->id, 'cap' => '150', 'usage_summary' => true])
        ->assertSessionHas('status', 'Saved.');
    Http::fake(['api.paystack.co/transaction/charge_authorization' => Http::sequence()->push(topUpCharge(11500), 200)->push(topUpCharge(11500, 'failed'), 200)]);
    $before = ($this->balance)();

    ($this->low)();
    expect(Payment::query()->where('purpose', 'credits')->where('status', 'succeeded')->value('total_cents'))->toBe(11500)    // R100 + VAT
        ->and(($this->balance)() - $before)->toBe(100000);                                                                   // 100 credits
    ($this->sent)('Credits topped up');

    ($this->low)();                                                                                                          // R100 more would pass R150
    ($this->sent)('Credits running low: monthly top-up limit reached');
    Http::assertSentCount(1);

    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => null]);
    $this->travelTo(CarbonImmutable::parse('2027-06-03 10:00', 'Africa/Johannesburg'));                                    // a new month
    ($this->low)();
    ($this->sent)('Credits running low: no saved card');
    BillingAccount::query()->whereKey($this->glow->id)->update(['default_method_id' => PaymentMethod::query()->value('id')]);
    ($this->low)();
    ($this->sent)('Credits not topped up: card declined');

    BillingAccount::query()->whereKey($this->glow->id)->update(['auto_top_up' => false]);
    Mail::fake();
    ($this->low)();
    Mail::assertNothingSent();
});

it('emails a monthly usage summary on the 1st, once — unless switched off', function () {
    DB::table('credit_usage_daily')->insert(['day' => '2027-04-12', 'tenant_id' => $this->glow->id, 'channel' => 'sms', 'used_milli' => 3000, 'messages' => 3]);
    $this->travelTo(CarbonImmutable::parse('2027-05-01 07:00', 'Africa/Johannesburg'));
    $this->artisan('billing:usage-summaries');
    Mail::assertNothingSent();                                                                                               // before 08:00
    $this->travelTo(CarbonImmutable::parse('2027-05-01 09:00', 'Africa/Johannesburg'));
    $this->artisan('billing:usage-summaries');
    $this->artisan('billing:usage-summaries');
    Mail::assertSent(CreditsMail::class, 1);
    Mail::assertSent(CreditsMail::class, fn (CreditsMail $m) => $m->subjectLine === 'Your credits for April 2027' && in_array('  SMS: 3 credits (3 messages)', $m->lines, true));

    BillingAccount::query()->whereKey($this->glow->id)->update(['usage_summary' => false]);
    $this->travelTo(CarbonImmutable::parse('2027-06-01 09:00', 'Africa/Johannesburg'));
    $this->artisan('billing:usage-summaries');
    Mail::assertSent(CreditsMail::class, 1);                                                                                 // switched off
});
