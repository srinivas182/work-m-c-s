<?php

declare(strict_types=1);

namespace Wurk\Platform\Database;

use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Support\Fluent;

/**
 * Year 2038 (Sprint 18.4b): on MySQL, timestamp()/timestamps()/softDeletes() create DATETIME columns, so no table WURK
 * creates can hold a date that stops in 2038 — even an older migration run on a fresh database (a new shard, tests).
 */
final class DatesPast2038Grammar extends MySqlGrammar
{
    /** @param  Fluent<string, mixed>  $column */
    protected function typeTimestamp(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }

    /** @param  Fluent<string, mixed>  $column */
    protected function typeTimestampTz(Fluent $column): string
    {
        return $this->typeDateTime($column);
    }
}
