<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('collection_enabled')->default(true);
            $table->string('collection_address', 300)->nullable();
            $table->boolean('delivery_enabled')->default(false);
            $table->unsignedInteger('delivery_fee_cents')->default(0);
            $table->string('delivery_note', 300)->nullable();        // e.g. areas delivered to
            $table->boolean('offline_enabled')->default(false);      // pay by EFT or on collection
            $table->text('offline_instructions')->nullable();        // e.g. bank details and reference
            $table->unsignedSmallInteger('offline_hold_hours')->default(72);
            $table->unsignedInteger('next_order_number')->default(1001);
            $table->timestamps();
        });

        Schema::create('shop_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->unsignedInteger('number');
            $table->string('status', 20)->index();   // awaiting_payment | paid | packed | ready | out_for_delivery | done | cancelled | expired
            $table->string('payment_method', 10);    // offline | online
            $table->string('fulfilment', 12);        // collection | delivery
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40);
            $table->string('customer_email', 160)->nullable();
            $table->string('delivery_address', 400)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('delivery_cents')->default(0);
            $table->unsignedInteger('total_cents');  // VAT inclusive; no platform fee (WURK takes none)
            $table->timestamp('hold_expires_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        // Names and prices are copied in, so later product edits never change past orders.
        Schema::create('shop_order_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('order_id')->index();
            $table->ulid('variant_id');
            $table->string('product_name', 120);
            $table->string('variant_label', 120)->nullable();
            $table->unsignedInteger('unit_cents');
            $table->unsignedSmallInteger('quantity');
            $table->boolean('stock_tracked');        // whether stock was taken off (and so must go back)
            $table->unsignedInteger('line_cents');
        });
    }

    public function down(): void
    {
        foreach (['shop_order_items', 'shop_orders', 'shop_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
