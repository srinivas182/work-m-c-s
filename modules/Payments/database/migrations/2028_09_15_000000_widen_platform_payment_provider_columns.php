<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Longer provider names (Sprint 22: flutterwave, airtel_money, mtn_momo).
        Schema::table('platform_payment_methods', fn (Blueprint $table) => $table->string('provider', 20)->change());
        Schema::table('platform_payment_accounts', fn (Blueprint $table) => $table->string('provider', 20)->change());
        Schema::table('platform_payment_attempts', fn (Blueprint $table) => $table->string('provider', 20)->change());
    }

    public function down(): void
    {
        Schema::table('platform_payment_methods', fn (Blueprint $table) => $table->string('provider', 10)->change());
        Schema::table('platform_payment_accounts', fn (Blueprint $table) => $table->string('provider', 10)->change());
        Schema::table('platform_payment_attempts', fn (Blueprint $table) => $table->string('provider', 10)->change());
    }
};
