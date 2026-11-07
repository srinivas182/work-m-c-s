<?php

declare(strict_types=1);

namespace Wurk\Referrals\Contracts;

/** What a customer sees of the referral programme in their portal (Sprint 13.6a). */
interface CustomerRewards
{
    /**
     * Null when the business isn't running a programme.
     *
     * @return array{code: string, link: string, referred: list<array{name: string, since: \DateTimeInterface}>, pending_cents: int, wallet_cents: int, expiry_months: int}|null
     */
    public function forContact(string $contactId): ?array;

    /**
     * Every reward and wallet movement for a customer (oldest first), for a copy of their data.
     *
     * @return list<array{at: \DateTimeInterface, kind: string, cents: int, available_at: \DateTimeInterface}>
     */
    public function statement(string $contactId): array;

    /** Record a wallet payout made outside WURK (EFT with a reference, or cash); never more than is available. Returns the amount. */
    public function payOut(string $contactId, int $cents, string $note): int;

    /** Close the customer's part in the programme (a deletion): pending rewards forfeited; their referral link stops working. */
    public function close(string $contactId): void;
}
