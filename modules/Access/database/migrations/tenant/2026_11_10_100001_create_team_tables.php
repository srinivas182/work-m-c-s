<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business-side record of a person on the team.
        Schema::create('members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('identity_id');
            $table->string('display_name');
            $table->string('status', 20)->default('active');
            $table->boolean('all_branches')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'identity_id']);
        });

        // Custom business roles. System roles are defined in code (ADR-0009).
        Schema::create('roles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 80);
            $table->json('permissions');
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('member_roles', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->ulid('member_id');
            $table->string('role_key', 40);

            $table->primary(['tenant_id', 'member_id', 'role_key']);
        });

        Schema::create('branch_member', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->ulid('branch_id');
            $table->ulid('member_id');

            $table->primary(['tenant_id', 'branch_id', 'member_id']);
            $table->index(['tenant_id', 'member_id']);
        });
    }

    public function down(): void
    {
        foreach (['branch_member', 'member_roles', 'roles', 'members'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
