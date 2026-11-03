<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real weights and sizes for courier rates (Sprint 12.6). Optional: missing ones use the business's default.
        foreach (['shop_products', 'shop_variants'] as $t) { // a variant's own values override its product's
            Schema::table($t, function (Blueprint $table) {
                $table->unsignedInteger('weight_g')->nullable();
                $table->unsignedInteger('length_mm')->nullable();
                $table->unsignedInteger('width_mm')->nullable();
                $table->unsignedInteger('height_mm')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['shop_products', 'shop_variants'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['weight_g', 'length_mm', 'width_mm', 'height_mm']));
        }
    }
};
