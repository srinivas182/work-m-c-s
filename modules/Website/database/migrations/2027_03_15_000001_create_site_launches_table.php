<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Time to launch: when each business was created and when it first published its home page.
        // Central, so WURK staff see every business (sites live on separate shards).
        Schema::create('site_launches', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('template_key', 60)->nullable();
            $table->timestamp('business_created_at')->nullable();
            $table->timestamp('launched_at');
            $table->unsignedInteger('minutes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_launches');
    }
};
