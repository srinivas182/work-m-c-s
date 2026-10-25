<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per business: where deposits are paid (a Paystack subaccount) and the cancellation policy.
        Schema::create('booking_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('payout_subaccount', 60)->nullable();
            $table->string('payout_label', 80)->nullable(); // e.g. "FNB ••••7890" — never the full number
            $table->unsignedSmallInteger('cancellation_hours')->default(24);
            $table->text('policy')->nullable();
            $table->timestamps();
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->unsignedInteger('deposit_cents')->default(0)->after('status');
            $table->string('payment_reference', 100)->nullable()->unique()->after('deposit_cents');
            $table->timestamp('hold_expires_at')->nullable()->after('payment_reference');
            $table->timestamp('paid_at')->nullable()->after('hold_expires_at');
            $table->timestamp('refunded_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['deposit_cents', 'payment_reference', 'hold_expires_at', 'paid_at', 'refunded_at']));
        Schema::dropIfExists('booking_settings');
    }
};
