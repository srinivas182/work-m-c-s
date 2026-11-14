<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recently deleted (Sprint 16.6b): products, their variants and categories can be put back for 30 days. */
    public function up(): void
    {
        foreach (['shop_products', 'shop_variants', 'shop_categories'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
        }
        // Which products a deleted category had, to put them back with it.
        Schema::table('shop_categories', fn (Blueprint $table) => $table->json('deleted_product_ids')->nullable());
    }

    public function down(): void
    {
        Schema::table('shop_categories', fn (Blueprint $table) => $table->dropColumn('deleted_product_ids'));
        foreach (['shop_products', 'shop_variants', 'shop_categories'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('deleted_at'));
        }
    }
};
