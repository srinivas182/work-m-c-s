<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // DATETIME, not TIMESTAMP: records are kept for years, and MySQL's TIMESTAMP ends on 19 January 2038.
    public function up(): void
    {
        Schema::create('record_patients', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 120);
            $table->string('email', 160)->nullable()->index();
            $table->string('phone', 40)->nullable();
            $table->dateTime('last_document_at')->nullable(); // retention runs from here
            $table->dateTime('flagged_at')->nullable();       // past retention: the owner may remove
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        // Never deleted during retention; a correction is a new version pointing at the original.
        Schema::create('record_documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('patient_id')->index();
            $table->ulid('original_id')->nullable()->index();  // set on corrections
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('title', 160);
            $table->string('note', 500)->nullable();            // e.g. what a correction changed
            $table->string('path', 300);                         // encrypted file on the private disk
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->string('uploaded_by', 120);
            $table->dateTime('created_at')->nullable();
        });
        // Every access: who, what, when (append-only).
        Schema::create('record_access_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('patient_id')->index();
            $table->ulid('document_id')->nullable();
            $table->string('action', 20);                        // upload | correct | view | download | link_sent | code_sent | code_ok | code_failed | removed
            $table->string('actor_type', 10);                    // staff | patient | system
            $table->string('actor', 120)->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('created_at')->nullable();
        });
        // A patient's private link: only hashes are stored.
        Schema::create('record_links', function (Blueprint $table) {
            $table->string('token_hash', 64)->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('patient_id')->index();
            $table->string('code_hash', 64)->nullable();
            $table->dateTime('code_expires_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['record_links', 'record_access_logs', 'record_documents', 'record_patients'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
