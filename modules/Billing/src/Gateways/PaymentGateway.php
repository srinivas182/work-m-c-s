<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

/** A card payment provider. Implementations never throw for provider errors. */
interface PaymentGateway
{
    public function name(): string;

    /**
     * Starts a hosted checkout; returns the URL to send the person to, or null on failure.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function checkout(string $email, int $amountCents, string $currency, string $reference, string $callbackUrl, array $metadata): ?string;

    /** Server-side confirmation of a charge by our reference. */
    public function verify(string $reference): GatewayResult;

    /** Charges a saved, reusable card. */
    public function chargeSaved(string $authorization, string $email, int $amountCents, string $currency, string $reference): GatewayResult;

    public function refund(string $reference, ?int $amountCents = null): bool;

    /**
     * Checks a webhook's signature and returns ['id', 'type', 'reference'] for a
     * successful charge, or null for anything unsigned, forged or irrelevant.
     *
     * @param  array<string, string>  $headers
     * @return array{id: string, type: string, reference: string}|null
     */
    public function parseWebhook(string $body, array $headers): ?array;
}
