<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

/**
 * Payments that go straight to a business (a Paystack subaccount), never through WURK —
 * booking deposits (Sprint 8.3). The business bears the payment provider's fee.
 */
interface SplitPayments
{
    /**
     * South African banks.
     *
     * @return list<array{code: string, name: string}>
     */
    public function banks(): array;

    /**
     * Create the business's payout account; returns its subaccount code.
     *
     * @throws \RuntimeException with a plain message
     */
    public function createSubaccount(string $businessName, string $bankCode, string $accountNumber): string;

    /**
     * A checkout page for a payment into the business's subaccount; null if payments are unavailable.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function checkout(string $email, int $amountCents, string $reference, string $callbackUrl, string $subaccount, array $metadata): ?string;

    /**
     * Whether the payment with this reference succeeded, and for how much (cents).
     *
     * @return array{paid: bool, amount_cents: int}
     */
    public function verify(string $reference): array;

    public function refund(string $reference): bool;
}
