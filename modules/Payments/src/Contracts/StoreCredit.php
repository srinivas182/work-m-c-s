<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * A customer's credit with the business (e.g. a referral wallet), usable towards what they pay for. Selling modules
 * use this without knowing where the credit comes from. Without a provider (no referral programme), there's none.
 */
interface StoreCredit
{
    /** What the customer could use now, in cents. */
    public function available(string $contactId): int;

    /** The most of an order of this total the customer may pay with credit (their balance, within the business's rules). */
    public function usableFor(string $contactId, int $orderTotalCents): int;

    /** Use credit towards an order; refused (an exception with a plain message) above what's usable. */
    public function spend(string $contactId, int $cents, string $sourceType, string $sourceId, int $orderTotalCents): void;

    /** A refund of an order paid partly with credit: this much goes back to the customer's credit. */
    public function giveBack(string $sourceType, string $sourceId, int $cents): void;

    /**
     * A short-lived signed pass proving the bearer is this customer, for checkouts that have no session (the site's
     * checkout). Issued only to a customer signed in to the portal.
     */
    public function passFor(string $contactId): string;

    /** The customer a pass was issued to — null if it's forged, expired, or for another business. */
    public function contactFromPass(string $pass): ?string;
}
