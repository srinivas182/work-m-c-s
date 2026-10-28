<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/** Paying WURK (e.g. an invoice) with the methods the super admin has enabled. */
interface PlatformPayments
{
    /**
     * Online methods WURK has enabled with keys for the current mode.
     *
     * @return list<string>
     */
    public function available(): array;

    /** Whether this payment was started through one of WURK's methods (so it's verified here). */
    public function handles(string $reference): bool;

    /** A method's name for people ("Yoco", "EFT / bank transfer"). */
    public function name(string $provider): string;

    /** WURK's EFT instructions, if EFT is enabled. */
    public function eftInstructions(): ?string;

    /**
     * Start a payment to WURK; returns where to send the person, or null if it can't start.
     *
     * @param  array{name: string, email?: string|null}  $customer
     * @param  array{return: string, cancel: string}  $urls
     */
    public function start(string $reference, int $amountCents, string $description, array $customer, array $urls, string $provider): ?string;

    /**
     * Ask the provider whether this payment succeeded, and for how much.
     *
     * @return array{paid: bool, amount_cents: int}
     */
    public function verify(string $reference): array;
}
