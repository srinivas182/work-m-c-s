<?php

declare(strict_types=1);

namespace Wurk\Payments\Support;

use Wurk\Payments\Contracts\StoreCredit;

/** No credit to use (the default, when no module provides store credit). */
final class NoStoreCredit implements StoreCredit
{
    public function available(string $contactId): int
    {
        return 0;
    }

    public function usableFor(string $contactId, int $orderTotalCents): int
    {
        return 0;
    }

    public function spend(string $contactId, int $cents, string $sourceType, string $sourceId, int $orderTotalCents, ?string $branchId = null): void
    {
        throw new \DomainException('There’s no credit to use here.');
    }

    public function giveBack(string $sourceType, string $sourceId, int $cents): void {}

    public function passFor(string $contactId): string
    {
        return '';
    }

    public function contactFromPass(string $pass): ?string
    {
        return null;
    }
}
