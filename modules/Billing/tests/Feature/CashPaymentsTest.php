<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Billing\Mail\CashApprovalCodeMail;
use Wurk\Billing\Mail\CashReceiptMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CashPayment;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Models\TenantSubscription;
use Wurk\Security\Support\Settings;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
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

    $this->clerk = $this->staffWithRole('finance', 'clerk@wurk.co.za');
    $this->approver = $this->staffWithRole('finance', 'approver@wurk.co.za');
    app(Settings::class)->setPlatform('cash_otp_recipients', [$this->clerk->id, $this->approver->id], null);
    app(Settings::class)->setPlatform('cash_receipt_recipients', ['finance@wurk.co.za'], null);
    $this->url = $this->admin("/businesses/{$this->glow->id}/cash");
});

function recordCash($test, array $overrides = []): void
{
    $test->actingAs($test->clerk)->post($test->url, ['purpose' => 'subscription', 'payer_name' => 'Thandi Mokoena', 'receipt_number' => 'RB-0042', ...$overrides])->assertSessionHasNoErrors();
}

function sentCode(): string
{
    $code = null;
    Mail::assertSent(CashApprovalCodeMail::class, function (CashApprovalCodeMail $m) use (&$code) {
        $code = $m->code;

        return true;
    });

    return (string) $code;
}

it('sends the approval code to the other approvers, never to the person who recorded it', function () {
    recordCash($this);

    Mail::assertSent(CashApprovalCodeMail::class, fn ($m) => $m->hasTo('approver@wurk.co.za') && $m->amount === 'R343.85');
    Mail::assertNotSent(CashApprovalCodeMail::class, fn ($m) => $m->hasTo('clerk@wurk.co.za'));
    expect(Payment::query()->value('status'))->toBe('pending')
        ->and(TenantSubscription::query()->find($this->glow->id)->status)->toBe('pending_payment');
});

it('refuses to record cash when nobody else can approve it', function () {
    app(Settings::class)->setPlatform('cash_otp_recipients', [$this->clerk->id], null);

    $this->actingAs($this->clerk)->post($this->url, ['purpose' => 'subscription', 'payer_name' => 'T', 'receipt_number' => 'RB-1'])->assertSessionHasErrors('cash');
    expect(Payment::query()->count())->toBe(0);
});

it('confirms the payment only with the right code, then activates and sends receipts', function () {
    recordCash($this);
    $cash = CashPayment::query()->firstOrFail();
    $approve = fn (string $code) => $this->actingAs($this->clerk)->post("{$this->url}/{$cash->id}/approve", ['code' => $code]);

    $approve('000000')->assertSessionHasErrors('code');
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('pending_payment');

    $approve(sentCode())->assertSessionHas('status');
    expect(TenantSubscription::query()->find($this->glow->id)->status)->toBe('active')
        ->and(Payment::query()->value('status'))->toBe('succeeded')
        ->and($cash->fresh()->only(['status', 'approved_by']))->toBe(['status' => 'approved', 'approved_by' => $this->clerk->id]);
    Mail::assertSent(CashReceiptMail::class, fn ($m) => $m->hasTo('finance@wurk.co.za') && $m->totalCents === 34385 && $m->vatCents === 4485);
    Mail::assertSent(CashReceiptMail::class, fn ($m) => $m->hasTo('owner@glow.co.za'));

    $approve(sentCode())->assertSessionHasErrors('code'); // already approved
});

it('locks after five wrong codes and expires codes after 15 minutes', function () {
    recordCash($this);
    $cash = CashPayment::query()->firstOrFail();
    foreach (range(1, 5) as $_) {
        $this->actingAs($this->clerk)->post("{$this->url}/{$cash->id}/approve", ['code' => '111111']);
    }
    $this->actingAs($this->clerk)->post("{$this->url}/{$cash->id}/approve", ['code' => sentCode()])->assertSessionHasErrors(['code' => 'Too many wrong codes. Send a new one.']);

    Mail::fake();
    $this->actingAs($this->clerk)->post("{$this->url}/{$cash->id}/resend")->assertSessionHas('status');
    $fresh = sentCode();
    $this->travel(16)->minutes();
    $this->actingAs($this->clerk)->post("{$this->url}/{$cash->id}/approve", ['code' => $fresh])->assertSessionHasErrors(['code' => 'That code has expired. Send a new one.']);
});

it('sells credits for cash, and cancelled cash can never be approved', function () {
    $pack = CreditPack::query()->create(['name' => 'Small', 'credits' => 100, 'price_cents' => 10000]);
    recordCash($this, ['purpose' => 'credits', 'pack_id' => $pack->id]);
    $this->actingAs($this->clerk)->post("{$this->url}/".CashPayment::query()->value('id').'/approve', ['code' => sentCode()]);
    expect(app(TenantContext::class)->run($this->glow, fn () => app(CreditWallet::class)->balance()['balance_milli']))->toBe(100000);

    Mail::fake();
    recordCash($this);
    $second = CashPayment::query()->where('status', 'awaiting_approval')->firstOrFail();
    $code = sentCode();
    $this->actingAs($this->clerk)->post("{$this->url}/{$second->id}/cancel")->assertSessionHas('status');
    $this->actingAs($this->clerk)->post("{$this->url}/{$second->id}/approve", ['code' => $code])->assertSessionHasErrors('code');
    expect(Payment::query()->whereKey($second->payment_id)->value('status'))->toBe('failed');
});

it('lets only staff allowed to record cash use it', function () {
    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($this->url)->assertForbidden();
    $this->actingAs($this->clerk)->get($this->url)->assertOk();
});
