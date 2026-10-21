<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 60)->unique();
            // Encrypted connection settings (driver, host, port, database, username, password).
            $table->text('connection');
            $table->string('status', 20)->default('active')->index();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('tenant_count')->default(0);
            $table->unsignedInteger('schema_version')->default(0);
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shards');
    }
};
