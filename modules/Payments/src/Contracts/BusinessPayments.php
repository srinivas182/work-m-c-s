<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * Card payments into the business's OWN merchant account (PayFast or Yoco) — deposits, class places and
 * shop orders. WURK never holds or touches the money. A payment is confirmed only by asking the provider.
 */
interface BusinessPayments
{
    /**
     * Providers this business (or a branch: its own accounts, else the business's) has switched on (e.g. ['yoco']).
     *
     * @return list<string>
     */
    public function available(?string $branchId = null): array;

    /**
     * Start a payment. Returns where to send the customer: a redirect URL (Yoco) or an auto-submitting
     * form page on the business's own address (PayFast requires a POST).
     *
     * @param  array{name: string, email?: string|null}  $customer
     *                                                              The reference must be unique across all businesses (use a ULID-based one, e.g. "shop-<order id>").
     * @param  array{return: string, cancel: string}  $urls
     */
    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, ?string $provider = null, ?string $branchId = null): ?string;

    /**
     * Ask the provider whether this payment succeeded, and for how much.
     *
     * @return array{paid: bool, amount_cents: int}
     */
    public function verify(string $reference): array;

    /** Refund all or part of a paid payment (cents); false if the provider refused — the owner refunds by hand. */
    public function refund(string $reference, ?int $amountCents = null): bool;

    /** The business's EFT / bank transfer instructions — only if EFT is enabled, allowed by WURK and saved for its mode. */
    public function eftInstructions(?string $branchId = null): ?string;

    /** Switch on EFT / bank transfer with these instructions (demo shops, onboarding). */
    public function setUpEft(string $instructions): void;

    /** Which branch's own account received a payment (null = the business-wide account). */
    public function receivedBy(string $reference): ?string;

    /** Which branch's own account a payment by this method at this branch would go to (null = the business's). */
    public function accountBranch(string $provider, ?string $branchId): ?string;
}
