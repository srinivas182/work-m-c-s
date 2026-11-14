<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** "Ask WURK: a question" after the free monthly questions — 1 credit in South Africa (16.2 decision A; editable on the rate card). */
    public function up(): void
    {
        if (Schema::hasTable('credit_rates')) {
            DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => 'ai.assistant', 'credits_milli' => 1000, 'effective_from' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('credit_rates')->where('channel', 'ai.assistant')->delete();
    }
};
