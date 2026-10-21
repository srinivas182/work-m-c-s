<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identities', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('first_name');
            $table->string('last_name')->default('');
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone', 20)->nullable()->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->string('password')->nullable();
            $table->string('locale', 10)->default('en');
            $table->string('status', 20)->default('active');
            $table->rememberToken();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::create('identity_mfa', function (Blueprint $table) {
            $table->foreignUlid('identity_id')->primary()->constrained('identities')->cascadeOnDelete();
            $table->string('type', 20)->default('totp');
            $table->text('secret');
            $table->text('recovery_codes')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_challenges', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->nullable()->constrained('identities')->cascadeOnDelete();
            $table->string('channel', 20);
            $table->string('destination_hash', 64)->index();
            $table->string('code_hash', 64);
            $table->string('purpose', 20);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('identity_id')->constrained('identities')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['identity_id', 'tenant_id', 'kind']);
        });

        Schema::create('platform_staff', function (Blueprint $table) {
            $table->foreignUlid('identity_id')->primary()->constrained('identities')->cascadeOnDelete();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('identity_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary();
            $table->foreignUlid('identity_id')->constrained('identities')->cascadeOnDelete();
            $table->string('surface', 10);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('last_active_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        // Laravel's database session driver (used when SESSION_DRIVER=database).
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('user_id', 26)->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['sessions', 'identity_sessions', 'platform_staff', 'memberships', 'otp_challenges', 'identity_mfa', 'identities'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
