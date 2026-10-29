<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Delivery by courier (Bob Go) or to a Pargo pickup point; null = collection or the shop's own flat-fee delivery.
        Schema::table('shop_orders', function (Blueprint $table) {
            $table->string('shipping_provider', 20)->nullable();
            $table->string('shipping_service', 120)->nullable();
            $table->string('pickup_point', 60)->nullable();
            $table->string('pickup_point_name', 200)->nullable();
            $table->json('shipping_to')->nullable(); // the structured address couriers need
            $table->string('tracking_reference', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('label_url', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shop_orders', fn (Blueprint $table) => $table->dropColumn(['shipping_provider', 'shipping_service', 'pickup_point', 'pickup_point_name', 'shipping_to', 'tracking_reference', 'tracking_url', 'label_url']));
    }
};
