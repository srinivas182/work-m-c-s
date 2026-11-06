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
}
