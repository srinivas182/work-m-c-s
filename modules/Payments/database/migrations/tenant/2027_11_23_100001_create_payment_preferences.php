<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business's return window (Sprint 13.4, WURK's decision): no refunds in WURK after it closes, and
        // referral rewards become available when it does. 0 = no refunds (rewards usable at once).
        Schema::create('payment_preferences', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->unsignedSmallInteger('refund_days')->default(14);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_preferences');
    }
};
