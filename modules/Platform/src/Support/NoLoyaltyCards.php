<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\LoyaltyCards;

/** Without the Loyalty module, nobody has a card. */
final class NoLoyaltyCards implements LoyaltyCards
{
    public function forPhone(?string $phone): ?array
    {
        return null;
    }

    public function forContact(string $contactId): ?array
    {
        return null;
    }

    public function offer(?string $phone, int $cents, string $where): ?array
    {
        return null;
    }

    public function redeem(string $rewardId, string $sourceType, string $sourceId, int $discountCents): bool
    {
        return false;
    }

    public function release(string $sourceType, string $sourceId): void {}
}
