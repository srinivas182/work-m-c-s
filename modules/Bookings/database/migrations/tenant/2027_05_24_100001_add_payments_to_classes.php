<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_sessions', function (Blueprint $table) {
            $table->boolean('pay_online')->default(false); // the price is paid when booking (straight to the business)
        });
        Schema::table('class_attendees', function (Blueprint $table) {
            // 'pending_payment' is 15 characters: the 9.3 column (12) would reject it on MySQL.
            $table->string('status', 20)->default('confirmed')->change();
            $table->unsignedInteger('deposit_cents')->default(0);
            $table->string('payment_reference', 100)->nullable()->unique();
            $table->timestamp('hold_expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('class_attendees', function (Blueprint $table) {
            $table->dropColumn(['deposit_cents', 'payment_reference', 'hold_expires_at', 'paid_at', 'refunded_at']);
        });
        Schema::table('class_sessions', fn (Blueprint $table) => $table->dropColumn('pay_online'));
    }
};
