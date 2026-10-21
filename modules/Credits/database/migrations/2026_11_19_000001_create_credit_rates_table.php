<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Effective-dated: a price change adds a row, so history is never rewritten.
        Schema::create('credit_rates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('country', 2);
            $table->string('channel', 20); // sms | whatsapp | email | ai
            $table->unsignedInteger('credits_milli'); // thousandths of a credit per message / action
            $table->timestamp('effective_from');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['country', 'channel', 'effective_from']);
        });

        // Starting South Africa rate card (Sprint 2 decision 7).
        $now = now();
        foreach (['sms' => 3000, 'whatsapp' => 5000, 'email' => 100, 'ai' => 2000] as $channel => $milli) {
            DB::table('credit_rates')->insert([
                'id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => $channel,
                'credits_milli' => $milli, 'effective_from' => $now, 'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_rates');
    }
};
