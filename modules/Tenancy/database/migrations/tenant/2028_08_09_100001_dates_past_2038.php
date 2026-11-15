<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Wurk\Platform\Database\DatesPast2038;

/**
 * Sprint 18.4b: every TIMESTAMP column in the shard becomes DATETIME, so dates after 19 January 2038 can be stored.
 * One-way on purpose: going back would fail for any date past 2038 already saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        DatesPast2038::convert(DB::connection($this->getConnection()));
    }

    public function down(): void
    {
        // Nothing: DATETIME holds everything TIMESTAMP did.
    }
};
