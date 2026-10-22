<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Billing\Gateways\GatewayResult;
use Wurk\Billing\Mail\CreditNoteMail;
use Wurk\Billing\Mail\InvoiceMail;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Invoice;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingSettings;
use Wurk\Billing\Support\Invoices;
use Wurk\Billing\Support\Payments;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $package = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 0, 'storage_mb' => 1024, 'staff_limit' => 3,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $package->id, 'monthly', allowTrial: false);
    BillingAccount::query()->create(['tenant_id' => $this->glow->id, 'billing_email' => 'owner@glow.co.za', 'billing_name' => 'Glow Salon (Pty) Ltd', 'vat_number' => '4123456789', 'address' => '12 Vilakazi St, Soweto']);
});

/** A settled payment of the given kind; returns its invoice. */
function paid($test, string $gateway = 'paystack', string $purpose = 'subscription', int $excl = 29900): Invoice
{
    $payments = app(Payments::class);
    $payment = $payments->create($test->glow->id, $purpose, $excl, ['package' => 'starter', 'period' => 'monthly', 'credits' => 100, 'pack' => 'Small'], $gateway);
    $payments->settle($payment->reference, new GatewayResult('succeeded', $payment->total_cents, 'ZAR', 'g'.$payment->id));

    return Invoice::query()->where('payment_id', $payment->id)->firstOrFail();
}

it('issues one tax invoice per payment with gapless numbers and every SARS field', function () {
    $first = paid($this);
    $second = paid($this, purpose: 'credits', excl: 10000);

    expect([$first->number, $second->number])->toBe(['WURK-INV-000001', 'WURK-INV-000002'])
        ->and($first->only(['excl_cents', 'vat_cents', 'total_cents', 'vat_bp']))->toBe(['excl_cents' => 29900, 'vat_cents' => 4485, 'total_cents' => 34385, 'vat_bp' => 1500])
        ->and($first->customer)->toMatchArray(['name' => 'Glow Salon (Pty) Ltd', 'vat_number' => '4123456789'])
        ->and($second->lines()->value('description'))->toContain('100 credits');

    $html = view('billing::invoice', ['invoice' => $first, 'lines' => $first->lines()->get(), 'credits' => collect(), 'placeholder' => false])->render();
    foreach (['Tax Invoice', 'WURK-INV-000001', $first->issuer['name'], $first->issuer['vat_number'], 'Glow Salon (Pty) Ltd', '4123456789', 'R299.00', 'R44.85', 'R343.85'] as $needed) {
        expect($html)->toContain($needed);
    }
    expect(app(Invoices::class)->pdf($first))->toStartWith('%PDF');
    Mail::assertSent(InvoiceMail::class, fn ($m) => $m->hasTo('owner@glow.co.za') && $m->invoice->is($first) && count($m->attachments()) === 1);
});

it('never invoices the refunded R1 card check, and never invoices a payment twice', function () {
    $payments = app(Payments::class);
    $check = $payments->create($this->glow->id, 'card_check', 100, [], 'paystack');
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true], 200)]);
    $payments->settle($check->reference, new GatewayResult('succeeded', $check->total_cents, 'ZAR', 'x'));

    $invoice = paid($this);
    app(Invoices::class)->forPayment(Payment::query()->findOrFail($invoice->payment_id));
    expect(Invoice::query()->count())->toBe(1);
});

it('keeps issued invoices unchanged, even when the issuer details change later', function () {
    $invoice = paid($this);
    app(BillingSettings::class)->setIssuer(['name' => 'WURK Holdings (Pty) Ltd', 'address' => 'Real Street', 'vat_number' => '4999999999', 'registration' => ''], null);

    expect($invoice->fresh()->issuer['name'])->toBe(BillingSettings::DEFAULT_ISSUER['name'])
        ->and(fn () => $invoice->update(['total_cents' => 1]))->toThrow(LogicException::class, 'immutable')
        ->and(fn () => $invoice->delete())->toThrow(LogicException::class)
        ->and(paid($this)->issuer['name'])->toBe('WURK Holdings (Pty) Ltd');
});

it('credits invoices: card refunds automatically, cash by EFT, never more than is left', function () {
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true], 200)]);
    $card = paid($this);
    $cash = paid($this, gateway: 'cash');
    $invoices = app(Invoices::class);

    $partial = $invoices->credit($card, 10000, 'Goodwill for downtime', null);
    expect($partial->only(['number', 'excl_cents', 'vat_cents', 'total_cents', 'refund', 'refund_status']))
        ->toBe(['number' => 'WURK-CN-000001', 'excl_cents' => 10000, 'vat_cents' => 1500, 'total_cents' => 11500, 'refund' => 'card', 'refund_status' => 'done']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/refund') && $r['amount'] === 11500);

    $rest = $invoices->credit($card->fresh(), null, 'Cancelled', null);
    expect($rest->total_cents)->toBe(34385 - 11500)->and($card->fresh()->credited_cents)->toBe(34385);
    expect(fn () => $invoices->credit($card->fresh(), 100, 'More', null))->toThrow(ValidationException::class);

    expect($invoices->credit($cash, null, 'Paid twice', null)->only(['refund', 'refund_status']))->toBe(['refund' => 'eft', 'refund_status' => 'pending']);
    Mail::assertSent(CreditNoteMail::class, 3);
});

it('lets owners download only their own invoices, and staff with permission credit them', function () {
    $invoice = paid($this);
    $owner = $this->person();
    $this->member($owner, $this->glow);
    app(TenantContext::class)->run($this->glow, fn () => app(Team::class)->add($owner->id, 'O', 'owner'));
    app(Catalog::class)->markPaid($this->glow->id, now(), now()->addMonth());

    $this->actingAs($owner)->get($this->app("/glow-salon/settings/billing/invoices/{$invoice->id}"))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $other = $this->createTenant('other-biz');
    $this->member($owner, $other);
    app(TenantContext::class)->run($other, fn () => app(Team::class)->add($owner->id, 'O', 'owner'));
    $this->actingAs($owner)->get($this->app("/other-biz/settings/billing/invoices/{$invoice->id}"))->assertNotFound();

    $url = $this->admin("/businesses/{$this->glow->id}/invoices/{$invoice->id}/credit");
    $this->actingAs($this->staffWithRole('support'))->post($url, ['reason' => 'Goodwill credit'])->assertForbidden();
    Http::fake(['api.paystack.co/refund' => Http::response(['status' => true], 200)]);
    $this->actingAs($this->staffWithRole('finance', 'finance@wurk.co.za'))->post($url, ['reason' => 'Goodwill credit'])->assertSessionHas('status');
});

it('validates invoice settings and flags placeholder details', function () {
    $admin = $this->staffWithRole('super-admin');
    $this->actingAs($admin)->get($this->admin('/billing/settings'))->assertInertia(fn ($p) => $p->where('placeholder', true));

    $this->actingAs($admin)->put($this->admin('/billing/settings'), ['name' => 'WURK (Pty) Ltd', 'address' => '5 Real Road, Sandton', 'vat_number' => '123', 'retry_days' => [3], 'restrict_from_day' => 8, 'suspend_on_day' => 5])
        ->assertSessionHasErrors(['vat_number', 'suspend_on_day']);
    $this->actingAs($admin)->put($this->admin('/billing/settings'), ['name' => 'WURK (Pty) Ltd', 'address' => '5 Real Road, Sandton', 'vat_number' => '4111111111', 'retry_days' => [7, 3, 3], 'restrict_from_day' => 8, 'suspend_on_day' => 15])
        ->assertSessionHas('status');
    expect(app(BillingSettings::class)->dunning()['retry_days'])->toBe([3, 7])
        ->and(app(BillingSettings::class)->issuerIsPlaceholder())->toBeFalse();
});
