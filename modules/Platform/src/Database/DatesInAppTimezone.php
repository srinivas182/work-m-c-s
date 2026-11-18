<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Carbon\CarbonImmutable;

/**
 * Times are stored as wall-clock time in the app's time zone (APP_TIMEZONE). Laravel formats a date given to a query
 * as its own wall-clock time, whatever its zone, so a range built in UTC or in a business's zone compared two hours
 * out near midnight: a sale at 23:30 in Johannesburg was missing from that day's cash-up (found 18 Nov 2026).
 * Every date bound to a query is first moved to the app's zone, the same way values are stored.
 */
trait DatesInAppTimezone
{
    /**
     * @param  array<array-key, mixed>  $bindings
     * @return array<array-key, mixed>
     */
    public function prepareBindings(array $bindings)
    {
        $zone = (string) config('app.timezone');
        foreach ($bindings as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $bindings[$key] = CarbonImmutable::instance($value)->setTimezone($zone);
            }
        }

        return parent::prepareBindings($bindings);
    }
}
