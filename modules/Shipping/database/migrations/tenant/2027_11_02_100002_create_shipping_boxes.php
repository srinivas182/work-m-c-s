<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The boxes a business ships in (inside sizes and weight limit); each order goes in the smallest that fits.
        Schema::create('shipping_boxes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 40);
            $table->unsignedInteger('length_mm');
            $table->unsignedInteger('width_mm');
            $table->unsignedInteger('height_mm');
            $table->unsignedInteger('max_weight_g');
            $table->unsignedSmallInteger('position')->default(0);
        });
        // The weight for items without one (starts at 0.5 kg, as before weights).
        Schema::create('shipping_preferences', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->unsignedInteger('default_item_g')->default(500);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_preferences');
        Schema::dropIfExists('shipping_boxes');
    }
};
