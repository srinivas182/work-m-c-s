<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owners who replied STOP to the Monday summary on WURK's number (Sprint 23.5b), by phone number (digits).
        Schema::create('money_weekly_stops', function (Blueprint $table) {
            $table->string('phone', 20)->primary();
            $table->dateTime('stopped_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_weekly_stops');
    }
};
