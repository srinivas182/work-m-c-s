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
