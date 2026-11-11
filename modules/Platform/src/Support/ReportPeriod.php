<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Carbon\CarbonImmutable;

/**
 * A report's period (Sprint 15.1): from..to, the same length immediately before it for comparison, and optionally one
 * branch (null = all the branches this person may see). Times are in the stored time zone (app.timezone), as 13.9a learned.
 */
final readonly class ReportPeriod
{
    /**
     * @param  list<string>|null  $branchIds  null = every branch
     * @param  bool  $forOwners  the owners' scheduled summary (no one signed in; every section the package has)
     */
    public function __construct(public CarbonImmutable $from, public CarbonImmutable $to, public ?array $branchIds = null, public bool $forOwners = false) {}

    /** @param list<string>|null $branchIds */
    public static function named(string $name, string $businessTz, ?array $branchIds = null): self
    {
        $now = CarbonImmutable::now($businessTz);
        $from = match ($name) {
            '7d' => $now->subDays(7), '30d' => $now->subDays(30), 'quarter' => $now->startOfQuarter(), 'year' => $now->startOfYear(),
            default => $now->startOfMonth(),
        };
        $stored = (string) config('app.timezone');

        return new self($from->setTimezone($stored), $now->setTimezone($stored), $branchIds);
    }

    /**
     * Chosen first and last days (Sprint 15.6), whole days in the given time zone; null if they don't make a range of at
     * most two years.
     *
     * @param  list<string>|null  $branchIds
     */
    public static function between(string $first, string $last, string $tz, ?array $branchIds = null): ?self
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $first, $tz);
            $to = CarbonImmutable::createFromFormat('!Y-m-d', $last, $tz);
        } catch (\Throwable) {
            return null;
        }
        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lt($from) || $from->diffInDays($to) > 731) {
            return null;
        }
        $stored = (string) config('app.timezone');

        return new self($from->setTimezone($stored), $to->addDay()->setTimezone($stored), $branchIds);
    }

    public function previousFrom(): CarbonImmutable
    {
        return $this->from->sub($this->from->diff($this->to));
    }

    public function previousTo(): CarbonImmutable
    {
        return $this->from;
    }

    /** Percent change (null when there was nothing before). */
    public static function change(int|float $now, int|float $before): ?float
    {
        return (float) $before === 0.0 ? null : round(($now - $before) / abs($before) * 100, 1);
    }
}
