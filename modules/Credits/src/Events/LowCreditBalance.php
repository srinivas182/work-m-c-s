<?php

declare(strict_types=1);

namespace Wurk\Credits\Events;

/** Raised once when a business's balance drops below its threshold (again after each top-up). */
final readonly class LowCreditBalance
{
    public function __construct(public string $tenantId, public int $balanceMilli, public int $thresholdMilli) {}
}
