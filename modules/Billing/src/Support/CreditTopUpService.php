<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Validation\ValidationException;
use Wurk\Billing\Contracts\CreditTopUps;
use Wurk\Billing\Models\BillingAccount;
use Wurk\Billing\Models\CreditPack;
use Wurk\Billing\Models\Payment;
use Wurk\Payments\Contracts\PlatformPayments;
use Wurk\Regions\Contracts\Countries;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Credit top-ups of any amount from R20 (Sprint 22.5), for Billing's own page and for other modules (23.4). */
final readonly class CreditTopUpService implements CreditTopUps
{
    public function __construct(private Payments $payments, private CashPayments $cash, private PlatformPayments $wurk, private TenantDirectory $tenants, private Countries $countries) {}

    public function methods(): array
    {
        $names = [];
        foreach ($this->wurk->available() as $provider) {
            $names[$provider] = $this->wurk->name($provider);
        }

        return $names;
    }

    /**
     * The credits an amount buys (VAT included in the amount), at the best pack's price per credit.
     *
     * @throws ValidationException
     */
    public function pack(string $tenantId, float $rand): CreditPack
    {
        if ($rand < self::MIN_RAND) {
            throw ValidationException::withMessages(['amount' => 'Top up at least R'.self::MIN_RAND.'.']);
        }
        $perCredit = app(PrepaidPlans::class)->centsPerCredit();
        $country = $this->tenants->find($tenantId)?->countryCode() ?? 'ZA';
        $vatBp = (int) ($this->countries->find($country)['vat_bp'] ?? 1500);
        $excl = (int) floor($rand * 100 * 10000 / (10000 + $vatBp));
        $credits = $perCredit === null ? 0 : (int) floor($excl / $perCredit);
        if ($credits < 1) {
            throw ValidationException::withMessages(['amount' => 'Credits aren’t on sale right now. Try again later.']);
        }

        return new CreditPack(['name' => 'Top-up', 'credits' => $credits, 'price_cents' => $excl]);
    }

    public function checkout(string $tenantId, int $rand, string $billingEmail, ?string $billingPhone, string $returnUrl, ?string $method): ?string
    {
        $pack = $this->pack($tenantId, $rand);
        $account = BillingAccount::query()->find($tenantId);
        if ($account === null) {
            BillingAccount::query()->create(['tenant_id' => $tenantId, 'billing_email' => $billingEmail, 'billing_phone' => $billingPhone]);
        } else {
            $account->update(['billing_email' => $billingEmail, 'billing_phone' => $billingPhone ?? $account->billing_phone]);
        }
        $result = $this->payments->checkout($tenantId, 'credits', $pack->price_cents, ['pack' => $pack->name, 'credits' => $pack->credits], $returnUrl,
            $method !== null && array_key_exists($method, $this->methods()) ? $method : null);

        return $result['url'];
    }

    public function status(string $tenantId, string $reference): string
    {
        $payment = Payment::query()->where('reference', $reference)->where('tenant_id', $tenantId)->where('purpose', 'credits')->first();
        if ($payment === null) {
            return 'failed';
        }
        $payment = $this->payments->confirm($payment->reference);

        return match ($payment->status) {
            'succeeded', 'refunded' => 'succeeded',
            'failed' => 'failed',
            default => 'pending',
        };
    }

    public function recordCash(string $tenantId, int $rand, string $payerName, string $receiptNumber, string $recordedBy): void
    {
        $pack = $this->pack($tenantId, $rand);
        $this->cash->record($tenantId, 'credits', $pack->price_cents, ['pack' => $pack->name, 'credits' => $pack->credits], $payerName, $receiptNumber, 'Collected by a WURK agent', $recordedBy);
    }
}
