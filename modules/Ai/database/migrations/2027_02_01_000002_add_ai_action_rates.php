<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /** Per-action WURK AI prices for South Africa (editable on the rate card; effective-dated). */
    public function up(): void
    {
        if (! Schema::hasTable('credit_rates')) {
            return;
        }
        $now = now();
        foreach (['ai.generate_site' => 20000, 'ai.rewrite' => 1000] as $channel => $milli) {
            DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => $channel, 'credits_milli' => $milli, 'effective_from' => $now, 'created_at' => $now]);
        }
    }

    public function down(): void
    {
        DB::table('credit_rates')->whereIn('channel', ['ai.generate_site', 'ai.rewrite'])->delete();
    }
};
