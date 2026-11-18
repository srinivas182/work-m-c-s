<?php

declare(strict_types=1);

namespace Wurk\Payments\Gateways\MobileMoney;

/**
 * Where a mobile money prompt stands, as the provider reports it (Sprint 22.2). A reason is given only for a failure:
 * declined (the customer said no), timeout (their phone didn't answer), funds (not enough in the wallet), pin (wrong
 * PIN), or other.
 */
final readonly class MobileMoneyResult
{
    public const REASONS = ['declined', 'timeout', 'funds', 'pin', 'other'];

    private function __construct(public string $state, public ?string $reason = null, public int $amountCents = 0) {}

    public static function paid(int $amountCents): self
    {
        return new self('paid', null, $amountCents);
    }

    public static function pending(): self
    {
        return new self('pending');
    }

    public static function failed(string $reason): self
    {
        return new self('failed', in_array($reason, self::REASONS, true) ? $reason : 'other');
    }
}
