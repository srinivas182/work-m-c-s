<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Custom platform roles. System roles are defined in code (ADR-0009).
        Schema::create('platform_roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 80)->unique();
            $table->json('permissions');
            $table->timestamps();
        });

        Schema::create('platform_role_assignments', function (Blueprint $table) {
            $table->foreignUlid('identity_id')->constrained('identities')->cascadeOnDelete();
            $table->string('role_key', 40);
            $table->timestamp('created_at')->nullable();

            $table->primary(['identity_id', 'role_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_role_assignments');
        Schema::dropIfExists('platform_roles');
    }
};
