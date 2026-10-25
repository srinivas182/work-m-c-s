<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The finished HTML of each published version, rendered at publish. Kept apart from the
        // (immutable) version so it can be re-rendered — e.g. after a branding change.
        Schema::create('page_renders', function (Blueprint $table) {
            $table->ulid('page_version_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('path', 255)->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->string('error', 255)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_renders');
    }
};
