<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Illuminate\Database\SQLiteConnection;

/** SQLite (local runs and tests) binds dates the same way as MySQL: in the app's zone. */
final class AppTimezoneSQLiteConnection extends SQLiteConnection
{
    use DatesInAppTimezone;
}
