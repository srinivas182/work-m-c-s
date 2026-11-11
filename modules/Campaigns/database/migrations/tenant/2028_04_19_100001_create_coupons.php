<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Coupons (Sprint 15.3b): amount or percent off the shop's goods and/or booking deposits.
        Schema::create('coupons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('code', 40);                 // stored upper-case
            $table->string('kind', 10);                 // amount | percent
            $table->unsignedInteger('value');           // cents, or basis points (1000 = 10%)
            $table->string('applies_to', 10);           // shop | bookings | both
            $table->unsignedInteger('min_spend_cents')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('once_per_customer')->default(false);
            $table->ulid('campaign_id')->nullable()->index();
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('coupon_id')->index();
            $table->string('source_type', 20);
            $table->string('source_id', 100);
            $table->unsignedInteger('discount_cents');
            $table->char('customer_key', 40)->nullable(); // one-way, from the phone number
            $table->dateTime('released_at')->nullable();
            $table->dateTime('created_at');
            $table->unique(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
