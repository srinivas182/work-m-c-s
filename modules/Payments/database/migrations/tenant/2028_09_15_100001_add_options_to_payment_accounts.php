<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Settings that aren't secret and don't depend on test or live, e.g. the Paystack channels customers see (Sprint 22.1).
        Schema::table('payment_accounts', fn (Blueprint $table) => $table->json('options')->nullable());
    }

    public function down(): void
    {
        Schema::table('payment_accounts', fn (Blueprint $table) => $table->dropColumn('options'));
    }
};
