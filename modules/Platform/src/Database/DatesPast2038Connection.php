<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Illuminate\Database\MySqlConnection;

/** A MySQL connection whose schema builder uses {@see DatesPast2038Grammar}, and whose dates are bound in the app's zone. */
final class DatesPast2038Connection extends MySqlConnection
{
    use DatesInAppTimezone;

    protected function getDefaultSchemaGrammar()
    {
        return new DatesPast2038Grammar($this);
    }
}
