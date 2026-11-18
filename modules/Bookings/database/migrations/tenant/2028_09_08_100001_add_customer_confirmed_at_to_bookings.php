<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sprint 21.5: when the customer tapped Confirm on their WhatsApp reminder (the diary shows it). */
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dateTime('customer_confirmed_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('customer_confirmed_at'));
    }
};
