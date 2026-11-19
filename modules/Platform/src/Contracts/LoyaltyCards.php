<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Loyalty cards (Sprint 26.5), for the modules that sell: show a customer's progress, offer their reward at the next
 * booking or checkout, and use it once. Inside the business. Without the Loyalty module, nobody has a card.
 */
interface LoyaltyCards
{
    /**
     * The customer's card, by phone number.
     *
     * @return array{stamps: int, needed: int, ready: int, reward: string, line: string}|null
     */
    public function forPhone(?string $phone): ?array;

    /** @return array{stamps: int, needed: int, ready: int, reward: string, line: string}|null */
    public function forContact(string $contactId): ?array;

    /**
     * The reward this customer could use on a purchase of `$cents` (shop or booking), or null.
     *
     * @return array{reward_id: string, discount_cents: int, label: string}|null
     */
    public function offer(?string $phone, int $cents, string $where): ?array;

    /** Uses the reward on a purchase (once). False if it's already been used. */
    public function redeem(string $rewardId, string $sourceType, string $sourceId, int $discountCents): bool;

    /** The purchase didn't stand (cancelled, expired): its reward can be used again. */
    public function release(string $sourceType, string $sourceId): void;
}
