<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** "AI: generate an image" — 8 credits in South Africa (editable on the rate card). */
    public function up(): void
    {
        if (Schema::hasTable('credit_rates')) {
            DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => 'ai.image', 'credits_milli' => 8000, 'effective_from' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('credit_rates')->where('channel', 'ai.image')->delete();
    }
};
