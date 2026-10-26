<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Product pages (/shop/{slug}), rendered ahead of time like pages: the site's header and footer
        // around the product's details. Stored content only; the shell is added when served.
        Schema::create('product_renders', function (Blueprint $table) {
            $table->ulid('product_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('slug', 130)->index();
            $table->string('path', 300);
            $table->timestamp('rendered_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_renders');
    }
};
