<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sprint 13.6c: part of a deposit paid from the customer's wallet; what's due is the deposit less this.
        foreach (['bookings', 'class_attendees'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->unsignedInteger('wallet_cents')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'class_attendees'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('wallet_cents'));
        }
    }
};
