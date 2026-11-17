<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Illuminate\Database\Connection;

/**
 * Year 2038 (Sprint 18.4b): MySQL's TIMESTAMP stops at 2038-01-19 03:14:07 UTC — a booking, subscription end or
 * retention date after that can't be stored. Every TIMESTAMP column becomes DATETIME, keeping its precision, nullability,
 * default, ON UPDATE and comment. Values keep the text the application wrote (WURK writes UTC). Done now, while there is
 * little production data; new migrations use datetime()/datetimes() (tests/Architecture/DatesPast2038Test.php).
 *
 * SQLite has no TIMESTAMP type (it stores text), so nothing changes there.
 */
final class DatesPast2038
{
    /** Returns the number of columns changed. */
    public static function convert(Connection $db): int
    {
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return 0;
        }
        $columns = $db->select("select TABLE_NAME as t, COLUMN_NAME as c, IS_NULLABLE as nullable, COLUMN_DEFAULT as def, EXTRA as extra,
                DATETIME_PRECISION as p, COLUMN_COMMENT as comment
            from information_schema.COLUMNS where TABLE_SCHEMA = database() and DATA_TYPE = 'timestamp' order by TABLE_NAME, ORDINAL_POSITION");
        $byTable = [];
        foreach ($columns as $col) {
            if (str_contains(strtolower((string) $col->extra), 'generated') && ! str_contains(strtolower((string) $col->extra), 'default_generated')) {
                continue; // a generated column follows its expression
            }
            $byTable[(string) $col->t][] = 'modify '.self::definition($db, (array) $col);
        }
        foreach ($byTable as $table => $changes) {
            $db->statement('alter table `'.str_replace('`', '``', $table).'` '.implode(', ', $changes));
        }

        return count($columns);
    }

    /** @param  array<string, mixed>  $col  one row of information_schema.COLUMNS */
    private static function definition(Connection $db, array $col): string
    {
        $p = (int) $col['p'];
        $type = $p > 0 ? "datetime({$p})" : 'datetime';
        $sql = '`'.str_replace('`', '``', (string) $col['c']).'` '.$type.($col['nullable'] === 'YES' ? ' null' : ' not null');
        $default = $col['def'] === null ? null : (string) $col['def'];
        if ($default !== null && stripos($default, 'current_timestamp') === 0) {
            $sql .= ' default '.$default;
        } elseif ($default !== null) {
            $sql .= ' default '.$db->getPdo()->quote($default);
        } elseif ($col['nullable'] === 'YES') {
            $sql .= ' default null';
        }
        if (stripos((string) $col['extra'], 'on update current_timestamp') !== false) {
            $sql .= ' on update current_timestamp'.($p > 0 ? "({$p})" : '');
        }
        if ((string) $col['comment'] !== '') {
            $sql .= ' comment '.$db->getPdo()->quote((string) $col['comment']);
        }

        return $sql;
    }
}
