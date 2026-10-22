<?php

declare(strict_types=1);

namespace Wurk\Billing\Gateways;

/**
 * A DebiCheck provider (Netcash, Peach Payments, Stitch…). The account holder
 * approves each mandate with their bank; collections settle days later, so
 * outcomes arrive as events. Implementations never throw for provider errors.
 */
interface DebitOrderProvider
{
    public function name(): string;

    /** Whether this provider can be used in the current environment. */
    public function available(): bool;

    /**
     * @param  array{reference: string, holder_name: string, id_number: string, bank: string, branch_code: string, account_number: string, account_type: string, collection_day: int, max_amount_cents: int}  $mandate
     * @return array{ok: bool, provider_id: string|null, message: string|null}
     */
    public function createMandate(array $mandate): array;

    /** Submits a collection; the outcome arrives later as an event. */
    public function collect(string $providerMandateId, int $amountCents, string $reference): GatewayResult;

    /**
     * @param  array<string, string>  $headers
     * @return array{id: string, type: 'mandate.approved'|'mandate.rejected'|'collection.succeeded'|'collection.failed', mandate: string|null, reference: string|null, amount: int|null, message: string|null}|null
     */
    public function parseEvent(string $body, array $headers): ?array;
}
