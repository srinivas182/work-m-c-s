<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Providers bill per message, not per credit (Sprint 15.5a): one charge per message, counted in the roll-up.
        Schema::table('credit_usage_daily', fn (Blueprint $t) => $t->unsignedInteger('messages')->default(0));
    }

    public function down(): void
    {
        Schema::table('credit_usage_daily', fn (Blueprint $t) => $t->dropColumn('messages'));
    }
};
