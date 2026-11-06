<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/**
 * A short-lived, signed proof of who a customer is (Sprint 13.6d): shown as a QR in their portal, scanned by staff in
 * Record sale. Unlike a referral code (public, shared), it can't be copied into someone else's purchase for long.
 */
interface CustomerIdentity
{
    public const PREFIX = 'WURK-ID:';

    /** A token for this customer at this business, valid for a few minutes. */
    public function tokenFor(string $contactId): string;

    /** The customer a token proves — null if forged, altered, expired, or for another business. */
    public function contactFromToken(string $token): ?string;
}
