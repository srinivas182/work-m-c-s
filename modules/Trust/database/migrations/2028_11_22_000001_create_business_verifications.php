<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Verified business (Sprint 25.4), one row per business, checked by WURK HQ:
 * - identity: the owner's ID document and a selfie (files encrypted on the private "trust" disk);
 * - registry: a company or close-corporation number matched by hand against the registry (CIPC in South Africa).
 * Documents are deleted 90 days after the decision; only the decision, its date and who made it are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_verifications', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('identity_status', 12)->default('none'); // none | pending | approved | refused
            $table->dateTime('identity_submitted_at')->nullable();
            $table->dateTime('identity_decided_at')->nullable();
            $table->dateTime('identity_expires_at')->nullable(); // redone every two years
            $table->string('identity_reason', 300)->nullable(); // why it was refused
            $table->string('registry_number', 30)->nullable();
            $table->string('registry_status', 12)->default('none');
            $table->dateTime('registry_submitted_at')->nullable();
            $table->dateTime('registry_decided_at')->nullable();
            $table->string('registry_reason', 300)->nullable();
            $table->ulid('decided_by')->nullable();
            $table->json('documents')->nullable(); // [{kind: id|selfie, path, mime}] — while they're kept
            $table->dateTime('documents_delete_after')->nullable();
            $table->datetimes();
            $table->index('identity_status');
            $table->index('registry_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_verifications');
    }
};
