<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

use Carbon\CarbonImmutable;

/**
 * The business's return window: refunds in WURK are refused once it closes, and referral rewards become available
 * when it does. Each selling module counts it from its own moment — an order from payment, a booking from the
 * appointment. 0 days = no refunds.
 */
interface RefundWindow
{
    public function days(): int;

    /** When something counted from this moment becomes final (no more refunds). */
    public function finalAt(\DateTimeInterface $from): CarbonImmutable;

    /** Whether a refund is still allowed for something counted from this moment. */
    public function open(\DateTimeInterface $from): bool;
}
