<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The return window is now the business's own (Settings → Payments), not the referral programme's (Sprint 13.4). */
    public function up(): void
    {
        Schema::table('referral_programmes', fn (Blueprint $table) => $table->dropColumn('return_days'));
    }

    public function down(): void
    {
        Schema::table('referral_programmes', fn (Blueprint $table) => $table->unsignedSmallInteger('return_days')->default(14));
    }
};
