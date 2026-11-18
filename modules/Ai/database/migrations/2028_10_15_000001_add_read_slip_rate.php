<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Reading an expense slip with WURK AI (Sprint 23.5b, decision E): 1 credit in South Africa (editable on the rate card). */
    public function up(): void
    {
        if (! Schema::hasTable('credit_rates')) {
            return;
        }
        $now = now();
        DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => 'ai.read_slip', 'credits_milli' => 1000, 'effective_from' => $now, 'created_at' => $now]);
    }

    public function down(): void
    {
        DB::table('credit_rates')->where('channel', 'ai.read_slip')->delete();
    }
};
