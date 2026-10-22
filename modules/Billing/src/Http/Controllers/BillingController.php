<?php

declare(strict_types=1);

namespace Wurk\Billing\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Wurk\Billing\Gateways\DebitOrderProvider;
use Wurk\Billing\Gateways\FakeDebitOrderProvider;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Mandate;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingStatus;
use Wurk\Billing\Support\Payments;
use Wurk\Billing\Support\SaBanking;
use Wurk\Messaging\Contracts\ProviderCredentials;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;

/** Business owners: Settings → Billing (PRD §7.14). */
final class BillingController
{
    public function __construct(private readonly TenantContext $context, private readonly Payments $payments, private readonly Catalog $catalog) {}

    public function show(Request $request, Countries $countries, ProviderCredentials $providers, BillingStatus $status, DebitOrderProvider $debitOrders): Response
    {
        $tenantId = $this->context->id();
        $sub = $this->catalog->subscriptionOf($tenantId);
        $vatBp = (int) ($countries->find($this->context->current()->countryCode())['vat_bp'] ?? 1500);
        $withVat = fn (int $cents) => $cents + intdiv($cents * $vatBp + 5000, 10000);
        $account = BillingAccount::query()->find($tenantId);
        $method = $this->payments->defaultMethod($tenantId);
        $user = $request->user();

        return Inertia::render('Billing::Billing', [
            'subscription' => $sub === null ? null : [...$sub, 'period_total_cents' => $withVat($sub['period_cents'])],
            'needsPayment' => $status->needsPayment($tenantId),
            'vatPercent' => $vatBp / 100,
            'account' => [
                'billing_email' => $account->billing_email ?? (string) ($user?->getAttribute('email') ?? ''),
                'billing_name' => $account->billing_name ?? $this->context->current()->displayName(),
                'vat_number' => $account?->vat_number,
                'saved' => $account !== null,
            ],
            'card' => $method === null ? null : ['brand' => $method->brand, 'last4' => $method->last4, 'expires' => $method->expires],
            'packs' => CreditPack::query()->where('active', true)->orderBy('display_order')->orderBy('credits')->get()
                ->map(fn (CreditPack $p) => ['id' => $p->id, 'name' => $p->name, 'credits' => $p->credits, 'total_cents' => $withVat($p->price_cents)])->all(),
            'payments' => Payment::query()->where('tenant_id', $tenantId)->latest()->limit(20)->get()
                ->map(fn (Payment $p) => ['id' => $p->id, 'at' => $p->created_at?->toIso8601String(), 'purpose' => $p->purpose, 'total_cents' => $p->total_cents, 'status' => $p->status, 'failure' => $p->failure])->all(),
            'gatewayReady' => $providers->active('payments.paystack') !== null,
            'debitOrders' => [
                'available' => $debitOrders->available(),
                'simulated' => $debitOrders instanceof FakeDebitOrderProvider && $debitOrders->available(),
                'banks' => SaBanking::BANKS,
                'accountTypes' => SaBanking::ACCOUNT_TYPES,
                'method' => $account->collection_method ?? 'card',
                'mandate' => ($m = Mandate::query()->where('tenant_id', $tenantId)->latest()->first()) === null ? null : [
                    'status' => $m->status, 'bank' => $m->bank, 'account' => $m->account_masked, 'day' => $m->collection_day, 'failure' => $m->failure,
                ],
            ],
        ]);
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'billing_email' => ['required', 'email', 'max:255'],
            'billing_name' => ['required', 'string', 'max:120'],
            'vat_number' => ['nullable', 'regex:/^4\d{9}$/'], // SA VAT numbers: 10 digits starting with 4
        ], ['vat_number.regex' => 'South African VAT numbers have 10 digits and start with 4.']);
        BillingAccount::query()->updateOrCreate(['tenant_id' => $this->context->id()], $data);

        return back()->with('status', 'Billing details saved.');
    }

    /** Pay for the subscription (first payment, end of trial, or catching up). */
    public function pay(): HttpResponse
    {
        $sub = $this->catalog->subscriptionOf($this->context->id()) ?? abort(404);

        return $this->toCheckout('subscription', $sub['period_cents'], ['package' => $sub['package_key'], 'period' => $sub['period']]);
    }

    public function buyCredits(Request $request): HttpResponse
    {
        $data = $request->validate(['pack_id' => ['required', 'exists:credit_packs,id'], 'saved_card' => ['boolean']]);
        $pack = CreditPack::query()->whereKey($data['pack_id'])->where('active', true)->firstOrFail();
        $details = ['pack' => $pack->name, 'credits' => $pack->credits];

        if (($data['saved_card'] ?? false) && $this->payments->defaultMethod($this->context->id()) !== null) {
            $payment = $this->payments->chargeSaved($this->context->id(), 'credits', $pack->price_cents, $details);

            return back()->with('status', $payment->status === 'succeeded' ? "{$pack->credits} credits added." : 'Your card was declined: '.$payment->failure);
        }

        return $this->toCheckout('credits', $pack->price_cents, $details);
    }

    /** Save a new card with a R1 check that is refunded straight away. */
    public function updateCard(): HttpResponse
    {
        return $this->toCheckout('card_check', 100, ['note' => 'Card check, refunded immediately']);
    }

    /** The person comes back from checkout; we confirm with Paystack ourselves. */
    public function returned(Request $request): RedirectResponse
    {
        $reference = (string) $request->query('reference', '');
        $payment = Payment::query()->where('reference', $reference)->where('tenant_id', $this->context->id())->firstOrFail();
        $payment = $this->payments->confirm($payment->reference);

        $message = match ($payment->status) {
            'succeeded', 'refunded' => match ($payment->purpose) {
                'credits' => 'Payment received — your credits are ready.',
                'card_check' => 'Card saved.',
                default => 'Payment received — thank you.',
            },
            'failed' => 'The payment didn’t go through: '.$payment->failure,
            default => 'We’re waiting for the bank to confirm your payment. This page updates when it does.',
        };

        return redirect()->route('tenant.billing')->with($payment->status === 'failed' ? 'warning' : 'status', $message);
    }

    /** @param  array<string, mixed>  $details */
    private function toCheckout(string $purpose, int $exclCents, array $details): HttpResponse
    {
        if (BillingAccount::query()->find($this->context->id()) === null) {
            return back()->with('warning', 'Add your billing email first.');
        }

        $result = $this->payments->checkout($this->context->id(), $purpose, $exclCents, $details, route('tenant.billing.return'));

        return $result['url'] === null
            ? back()->with('warning', 'Card payments aren’t available right now. Please try again shortly.')
            : Inertia::location($result['url']);
    }
}
