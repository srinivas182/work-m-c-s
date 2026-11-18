<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * "AI receptionist: a reply" — 0.2 credits in South Africa (Sprint 19, decision B: WURK's cost × margin;
     * a placeholder until WURK sets it on the rate card).
     */
    public function up(): void
    {
        if (Schema::hasTable('credit_rates')) {
            DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => 'ai.receptionist', 'credits_milli' => 200, 'effective_from' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('credit_rates')->where('channel', 'ai.receptionist')->delete();
    }
};
