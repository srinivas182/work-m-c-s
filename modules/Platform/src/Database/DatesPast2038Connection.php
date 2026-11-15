<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Illuminate\Database\MySqlConnection;

/** A MySQL connection whose schema builder uses {@see DatesPast2038Grammar}. */
final class DatesPast2038Connection extends MySqlConnection
{
    protected function getDefaultSchemaGrammar()
    {
        return new DatesPast2038Grammar($this);
    }
}
