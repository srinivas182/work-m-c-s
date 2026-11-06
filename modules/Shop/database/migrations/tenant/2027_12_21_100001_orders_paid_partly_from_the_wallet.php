<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sprint 13.6c: part of an order paid from the customer's wallet; what's due is the total less this.
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->unsignedInteger('wallet_cents')->default(0);
            $table->unsignedInteger('wallet_refunded_cents')->default(0); // given back to the wallet so far
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $table) => $table->dropColumn(['wallet_cents', 'wallet_refunded_cents']));
    }
};
