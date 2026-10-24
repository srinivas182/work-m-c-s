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
    public function charge(string $channel, string $key, string $description, ?string $by = null): int;

    /** Net credits spent this calendar month (debits less refunds), in thousandths; optionally by one staff member. */
    public function spentThisMonth(?string $by = null): int;

    /** Thousandths still allowed this month under the limits (null = no limit applies). */
    public function spendable(?string $by = null): ?int;

    /** @return array{monthly_milli: int|null, staff: array<string, int>} */
    public function limits(): array;

    /** @param  array<string, int>  $staffMilli */
    public function setLimits(?int $monthlyMilli, array $staffMilli, ?string $by): void;

    /**
     * Net credits spent this month per channel (debits less refunds).
     *
     * @return array<string, array{count: int, milli: int}>
     */
    public function usageThisMonth(): array;

    /** Refund a charge (e.g. a failed send). No-op when nothing was charged under that key. */
    public function refund(string $chargeKey, string $description): void;

    public function grant(string $type, int $amountMilli, string $key, string $description, ?string $by = null): void;

    /** @return array{balance_milli: int, allowance_milli: int, low_balance_milli: int} */
    public function balance(): array;

    public function rateFor(string $channel): int;
}
