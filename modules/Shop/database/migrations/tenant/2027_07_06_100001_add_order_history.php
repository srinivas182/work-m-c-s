<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->timestamp('paid_at')->nullable();
        });
        // Every change to an order: what, who, when (shown on the order as its timeline).
        Schema::create('shop_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('order_id')->index();
            $table->string('event', 30);              // placed | paid | packed | ready | out_for_delivery | done | cancelled | expired | refunded
            $table->unsignedInteger('amount_cents')->nullable(); // refunds
            $table->string('note', 300)->nullable();
            $table->string('by', 120)->nullable();    // a name, or "Customer", or "Payment provider"
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_order_events');
        Schema::table('shop_orders', fn (Blueprint $table) => $table->dropColumn(['refunded_cents', 'paid_at']));
    }
};
