<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * Every refund in one place (Sprint 25.5), whatever was refunded (an order, an in-store sale, an invoice, a deposit)
 * and however the money went back. Selling modules decide whether a refund is allowed and move the money through the
 * provider; this records it, counts counter refunds in the day's cash-up, and tells the customer.
 */
interface Refunds
{
    /** How the money went back. Under South Africa's CPA a customer owed a refund may insist on cash: store credit is a choice. */
    public const METHODS = [
        'provider' => 'Back to their card or account (paid online)',
        'cash' => 'Cash',
        'card_machine' => 'Card machine',
        'qr' => 'SnapScan / Zapper',
        'eft' => 'EFT',
        'mobile_money' => 'Mobile money',
        'store_credit' => 'Store credit',
    ];

    /** Paid back at the counter: money out of the day's takings. */
    public const AT_THE_COUNTER = ['cash', 'card_machine', 'qr'];

    /**
     * Records a refund; tells the customer (SMS, else email) when a number or address is given. Returns its id.
     *
     * @param  array{name?: string, phone?: string|null, email?: string|null, what?: string}  $customer
     */
    public function record(string $kind, string $sourceId, int $cents, string $method, string $reason, ?string $note, ?string $by, string $byName, ?string $branchId,
        array $customer = []): string;

    /**
     * The refunds of one order, sale, invoice or booking, oldest first.
     *
     * @return list<array{id: string, amount_cents: int, method: string, reason: string, note: string|null, by_name: string, refunded_at: string, told_by: string|null}>
     */
    public function for(string $kind, string $sourceId): array;

    /**
     * Paid back at the counter between two moments, by method (cash, card_machine, qr).
     *
     * @return array<string, int>
     */
    public function atTheCounter(\DateTimeInterface $from, \DateTimeInterface $to): array;

    /** Cents refunded for one kind (e.g. invoices) between two moments, however they were paid back. */
    public function totalFor(string $kind, \DateTimeInterface $from, \DateTimeInterface $to): int;
}
