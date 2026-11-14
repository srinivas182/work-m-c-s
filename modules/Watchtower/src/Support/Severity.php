<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

use Wurk\Watchtower\Models\Incident;

/**
 * How bad a problem is (Sprint 18.0) — by where it happens (money, signing in, booking, checkout), how many
 * businesses it hits, and how fast it's happening. Only ever goes up; the AI can raise it further (18.0b).
 */
final class Severity
{
    /** Routes, jobs and commands where a failure costs someone money or locks them out. */
    private const SENSITIVE = '/(sign-?in|identity|otp|mfa|password|payment|payout|billing|invoice|checkout|credit|wallet|refund|subscription|dunning|booking|order|messag)/i';

    public static function for(Incident $i, string $floor = 'normal'): string
    {
        $sensitive = preg_match(self::SENSITIVE, (string) $i->location) === 1;
        $hour = $i->lastHour();
        $computed = match (true) {
            $hour >= 100 && $i->businesses >= 5, $sensitive && $i->businesses >= 3 && $i->kind !== 'frontend' => 'critical',
            $sensitive, $hour >= 20 => 'high',
            default => 'normal',
        };

        return self::max(self::max($computed, $floor), $i->severity);
    }

    public static function max(?string $a, ?string $b): string
    {
        return Incident::rank($a) >= Incident::rank($b) ? ($a ?? 'normal') : ($b ?? 'normal');
    }
}
