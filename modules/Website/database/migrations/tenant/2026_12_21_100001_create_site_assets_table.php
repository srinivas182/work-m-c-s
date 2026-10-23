<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Uploaded website photos. Only re-encoded WebP variants are kept — never the
        // original file, so no camera metadata (GPS, device) is ever stored or served.
        Schema::create('site_assets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('name', 160);
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->unsignedInteger('bytes');   // all variants together; counts towards the storage quota
            $table->json('variants');           // width => storage path
            $table->ulid('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_assets');
    }
};
