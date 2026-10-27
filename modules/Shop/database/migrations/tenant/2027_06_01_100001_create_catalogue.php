<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 80);
            $table->string('slug', 90);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('shop_products', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('category_id')->nullable()->index();
            $table->string('name', 120);
            $table->string('slug', 130);
            $table->text('description')->nullable();
            $table->json('options')->nullable();            // option names, e.g. ["Size", "Colour"]
            $table->json('photos')->nullable();             // photo addresses from the media library
            $table->string('status', 12)->default('draft'); // draft | published
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'slug']);
        });

        // Every product has at least one variant (a simple product is a single one), so the cart and
        // stock never need special cases.
        Schema::create('shop_variants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('product_id')->index();
            $table->json('values')->nullable();                      // e.g. {"Size": "M", "Colour": "Red"}
            $table->string('label', 120)->nullable();                // e.g. "M / Red"
            $table->string('sku', 60)->nullable();
            $table->unsignedInteger('price_cents');                  // VAT inclusive
            $table->unsignedInteger('compare_at_cents')->nullable(); // "was" price
            $table->integer('stock')->nullable();                    // null = not tracked (unlimited)
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['shop_variants', 'shop_products', 'shop_categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
