<?php

declare(strict_types=1);

namespace Wurk\Credits\Contracts;

/**
 * The current business's WURK Credits. Every method works in the current
 * tenant context. Keys make every write idempotent: repeating a call with the
 * same key returns the original entry and never charges or grants twice.
 */
interface CreditWallet
{
    /**
     * Charge for one message/action at the business's country rate.
     *
     * @throws InsufficientCredits
     */
    public function charge(string $channel, string $key, string $description): int;

    /** Refund a charge (e.g. a failed send). No-op when nothing was charged under that key. */
    public function refund(string $chargeKey, string $description): void;

    public function grant(string $type, int $amountMilli, string $key, string $description, ?string $by = null): void;

    /** @return array{balance_milli: int, allowance_milli: int, low_balance_milli: int} */
    public function balance(): array;

    public function rateFor(string $channel): int;
}
