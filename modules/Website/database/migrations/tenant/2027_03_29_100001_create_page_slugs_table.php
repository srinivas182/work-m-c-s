<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old page addresses, so links shared before a rename still arrive (301 to the new address).
        Schema::create('page_slugs', function (Blueprint $table) {
            $table->string('slug', 80)->primary();
            $table->ulid('page_id')->index();
            $table->ulid('tenant_id')->index();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_slugs');
    }
};
