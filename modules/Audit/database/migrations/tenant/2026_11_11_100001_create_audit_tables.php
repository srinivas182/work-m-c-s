<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail. One chain per business (scope = tenant_id).
 * In production the application's database user has no UPDATE or DELETE
 * privilege on these tables (see docs/deployment/docker.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 30);
            $table->unsignedBigInteger('seq');
            $table->ulid('tenant_id')->index();
            $table->ulid('branch_id')->nullable();
            $table->string('actor_type', 20);
            $table->ulid('actor_identity_id')->nullable()->index();
            $table->string('actor_name')->nullable();
            $table->ulid('impersonator_identity_id')->nullable();
            $table->string('action', 80)->index();
            $table->string('subject_type', 80)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestamp('occurred_at', 6)->index();
            $table->char('prev_hash', 64);
            $table->char('hash', 64);

            $table->unique(['scope', 'seq']);
        });

        // Serialises appends per chain: the head row is locked while an event is written.
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('scope', 30)->primary();
            $table->ulid('tenant_id')->index();
            $table->unsignedBigInteger('seq');
            $table->char('hash', 64);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_events');
    }
};
