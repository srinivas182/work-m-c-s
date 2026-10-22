<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Carbon\CarbonImmutable;

/**
 * PRD §11.3. Upgrades are charged now for the rest of the current period;
 * downgrades and yearly → monthly wait for the next renewal. Amounts in cents, excl. VAT.
 */
final class Proration
{
    /**
     * @param  array{period: string, period_cents: int, current_period_start: string|null, current_period_end: string|null}  $current
     * @return array{kind: 'upgrade'|'scheduled'|'same', charge_cents: int, restart_period: bool}
     */
    public static function quote(array $current, string $newPeriod, int $newPeriodCents, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $oldPeriod = $current['period'];

        if ($oldPeriod === $newPeriod && $newPeriodCents === $current['period_cents']) {
            return ['kind' => 'same', 'charge_cents' => 0, 'restart_period' => false];
        }
        if (($oldPeriod === 'yearly' && $newPeriod === 'monthly') || ($oldPeriod === $newPeriod && $newPeriodCents < $current['period_cents'])) {
            return ['kind' => 'scheduled', 'charge_cents' => 0, 'restart_period' => false];
        }

        $fraction = self::remainingFraction($current['current_period_start'], $current['current_period_end'], $now);
        $unused = (int) round($current['period_cents'] * $fraction);

        if ($oldPeriod === 'monthly' && $newPeriod === 'yearly') {
            // A new yearly period starts now; the unused part of this month counts towards it.
            return ['kind' => 'upgrade', 'charge_cents' => max(0, $newPeriodCents - $unused), 'restart_period' => true];
        }

        // Same period length, more expensive: pay the difference for the days left.
        return ['kind' => 'upgrade', 'charge_cents' => max(0, (int) round($newPeriodCents * $fraction) - $unused), 'restart_period' => false];
    }

    public static function remainingFraction(?string $start, ?string $end, CarbonImmutable $now): float
    {
        if ($start === null || $end === null) {
            return 1.0;
        }
        $total = CarbonImmutable::parse($start)->diffInSeconds(CarbonImmutable::parse($end));
        $left = $now->diffInSeconds(CarbonImmutable::parse($end), false);

        return $total <= 0 ? 0.0 : max(0.0, min(1.0, $left / $total));
    }
}
