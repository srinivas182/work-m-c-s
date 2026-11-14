<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recently deleted (Sprint 16.6c): when a booking was cancelled, so it can be put back within 30 days. */
    public function up(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->timestamp('cancelled_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn('cancelled_at'));
    }
};
