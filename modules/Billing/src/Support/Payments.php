<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Events\PaymentFailed;
use Wurk\Billing\Gateways\GatewayResult;
use Wurk\Billing\Gateways\PaymentGateway;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Models\PaymentMethod;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Every payment goes through here. A payment is marked paid only on server-side
 * confirmation from the gateway (signed webhook or our own verify call), and
 * only if the confirmed amount and currency match what we asked for.
 */
final readonly class Payments
{
    public function __construct(
        private PaymentGateway $gateway,
        private Catalog $catalog,
        private Countries $countries,
        private TenantDirectory $tenants,
        private TenantContext $context,
        private CreditWallet $wallet,
        private AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     * @return array{payment: Payment, url: string|null}
     */
    public function checkout(string $tenantId, string $purpose, int $exclCents, array $details, string $returnUrl): array
    {
        $payment = $this->create($tenantId, $purpose, $exclCents, $details);
        $url = $this->gateway->checkout($this->email($tenantId), $payment->total_cents, $payment->currency, $payment->reference, $returnUrl, [
            'tenant_id' => $tenantId, 'purpose' => $purpose, 'payment_id' => $payment->id,
        ]);

        if ($url === null) {
            $payment->update(['status' => 'failed', 'failure' => 'Could not start checkout']);
        }

        return ['payment' => $payment, 'url' => $url];
    }

    /** @param  array<string, mixed>  $details */
    public function chargeSaved(string $tenantId, string $purpose, int $exclCents, array $details): Payment
    {
        $method = $this->defaultMethod($tenantId);
        $payment = $this->create($tenantId, $purpose, $exclCents, $details);

        if ($method === null) {
            return $this->settle($payment->reference, GatewayResult::failed('No saved card'));
        }

        $payment->update(['method_id' => $method->id]);

        return $this->settle($payment->reference, $this->gateway->chargeSaved($method->authorization, $this->email($tenantId), $payment->total_cents, $payment->currency, $payment->reference));
    }

    /** Confirms by reference with the gateway (e.g. when the person returns from checkout). */
    public function confirm(string $reference): Payment
    {
        return $this->settle($reference, $this->gateway->verify($reference));
    }

    public function settle(string $reference, GatewayResult $result): Payment
    {
        $applied = false;

        $payment = DB::transaction(function () use ($reference, $result, &$applied): Payment {
            $payment = Payment::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'pending' || $result->status === 'pending') {
                return $payment; // already settled, or nothing final yet
            }

            if ($result->status === 'failed') {
                $payment->update(['status' => 'failed', 'failure' => $result->message ?? 'Declined', 'gateway_id' => $result->gatewayId]);

                return $payment;
            }

            if ($result->amountCents !== $payment->total_cents || strtoupper((string) $result->currency) !== $payment->currency) {
                $payment->update(['status' => 'failed', 'failure' => 'Amount or currency did not match']);
                $this->audit->record('payment.mismatch', 'payment', $payment->id, ['expected' => $payment->total_cents, 'confirmed' => $result->amountCents, 'currency' => $result->currency], ['platform' => true]);

                return $payment;
            }

            $method = $result->card !== null && $result->card['reusable'] ? $this->saveCard($payment->tenant_id, $result->card) : null;
            $payment->update(['status' => 'succeeded', 'paid_at' => now(), 'gateway_id' => $result->gatewayId, 'method_id' => $method !== null ? $method->id : $payment->method_id]);
            $applied = true;

            return $payment;
        });

        if ($applied) {
            $this->apply($payment);
        } elseif ($payment->status === 'failed' && $payment->purpose === 'subscription') {
            event(new PaymentFailed($payment->tenant_id, $payment->id, (string) $payment->failure));
        }

        return $payment;
    }

    /** What a payment pays for, applied once (every step is idempotent by payment id). */
    private function apply(Payment $payment): void
    {
        $tenant = $this->tenants->find($payment->tenant_id);
        if ($tenant === null) {
            return;
        }

        if ($payment->purpose === 'subscription') {
            $sub = $this->catalog->subscriptionOf($payment->tenant_id);
            $start = CarbonImmutable::now();
            foreach ([$sub['trial_ends_at'] ?? null, $sub['current_period_end'] ?? null] as $later) {
                if ($later !== null && CarbonImmutable::parse($later)->gt($start)) {
                    $start = CarbonImmutable::parse($later); // paying early never loses trial or paid days
                }
            }
            $end = ($sub['period'] ?? 'monthly') === 'yearly' ? $start->addYear() : $start->addMonth();
            $this->catalog->markPaid($payment->tenant_id, $start, $end);

            $credits = (int) ($sub['monthly_credits'] ?? 0);
            if ($credits > 0) {
                $this->context->run($tenant, fn () => $this->wallet->grant('allowance', $credits * 1000, 'allowance:'.$start->format('Y-m'), "Monthly allowance ({$credits} credits)"));
            }
        }

        if ($payment->purpose === 'credits') {
            $credits = (int) ($payment->details['credits'] ?? 0);
            $this->context->run($tenant, fn () => $this->wallet->grant('purchase', $credits * 1000, 'purchase:'.$payment->id, "Bought {$credits} credits"));
        }

        if ($payment->purpose === 'card_check' && $this->gateway->refund($payment->reference)) {
            $payment->update(['status' => 'refunded']); // only saved the card; give the money back
        }

        $this->audit->record('payment.succeeded', 'payment', $payment->id, [
            'tenant_id' => $payment->tenant_id, 'purpose' => $payment->purpose, 'total' => $payment->total_cents / 100, 'currency' => $payment->currency,
        ], ['platform' => true]);
    }

    /** @param  array<string, mixed>  $details */
    private function create(string $tenantId, string $purpose, int $exclCents, array $details): Payment
    {
        $tenant = $this->tenants->find($tenantId) ?? throw new \InvalidArgumentException('Unknown business.');
        $country = $this->countries->find($tenant->countryCode());
        $vatBp = (int) ($country['vat_bp'] ?? 1500);
        $vat = intdiv($exclCents * $vatBp + 5000, 10000); // round half up to the cent

        return Payment::query()->create([
            'tenant_id' => $tenantId, 'purpose' => $purpose, 'reference' => 'WURK-'.Str::upper((string) Str::ulid()),
            'gateway' => $this->gateway->name(), 'currency' => (string) ($country['currency'] ?? 'ZAR'),
            'amount_excl_cents' => $exclCents, 'vat_cents' => $vat, 'total_cents' => $exclCents + $vat, 'vat_bp' => $vatBp, 'details' => $details,
        ]);
    }

    /** @param  array{code: string, reusable: bool, signature: string, brand: string|null, last4: string|null, expires: string|null}  $card */
    private function saveCard(string $tenantId, array $card): PaymentMethod
    {
        $method = PaymentMethod::query()->updateOrCreate(
            ['tenant_id' => $tenantId, 'gateway' => $this->gateway->name(), 'signature' => $card['signature']],
            ['authorization' => $card['code'], 'brand' => $card['brand'], 'last4' => $card['last4'], 'expires' => $card['expires']],
        );
        BillingAccount::query()->whereKey($tenantId)->update(['default_method_id' => $method->id]);

        return $method;
    }

    public function defaultMethod(string $tenantId): ?PaymentMethod
    {
        $id = BillingAccount::query()->whereKey($tenantId)->value('default_method_id');

        return $id === null ? null : PaymentMethod::query()->whereKey((string) $id)->first();
    }

    private function email(string $tenantId): string
    {
        return (string) BillingAccount::query()->whereKey($tenantId)->value('billing_email');
    }
}
