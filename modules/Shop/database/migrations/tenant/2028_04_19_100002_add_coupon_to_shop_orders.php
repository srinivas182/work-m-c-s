<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coupons (Sprint 15.3b): what a code took off the goods.
        Schema::table('shop_orders', function (Blueprint $t) {
            $t->unsignedInteger('discount_cents')->default(0);
            $t->string('coupon_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $t) => $t->dropColumn(['discount_cents', 'coupon_code']));
    }
};
