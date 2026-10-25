<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Times are stored the platform's way: wall-clock time in the app's time zone (APP_TIMEZONE,
 * Africa/Johannesburg). Every time Bookings stores or compares in a query goes through here,
 * so a moment never shifts by the time-zone offset on its way to the database and back.
 */
final class Instant
{
    public static function db(CarbonInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public static function fromDb(string $value): CarbonImmutable
    {
        return CarbonImmutable::parse($value, (string) config('app.timezone'));
    }
}
