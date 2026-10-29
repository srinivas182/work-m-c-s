<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One contact per person per business (POPIA: consent recorded; nothing deleted when merging).
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 160);
            $table->string('phone', 20)->nullable();           // normalised (+27…)
            $table->string('email', 160)->nullable();          // lower-case
            $table->json('tags')->nullable();
            $table->text('notes')->nullable();
            $table->dateTime('marketing_consent_at')->nullable();
            $table->string('marketing_consent_source', 60)->nullable();
            $table->ulid('merged_into')->nullable();           // merged contacts point at the one they joined
            $table->dateTime('last_seen_at')->nullable()->index();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'email']);
        });
        // What a contact has done: bookings, orders, class places, form replies, chats.
        Schema::create('contact_links', function (Blueprint $table) {
            $table->ulid('contact_id')->index();
            $table->ulid('original_contact_id')->index();   // who it first belonged to (so a merge can be undone)
            $table->ulid('tenant_id')->index();
            $table->string('source_type', 20);                 // booking | order | class | form | chat
            $table->string('source_id', 100);
            $table->ulid('branch_id')->nullable()->index();
            $table->string('summary', 200);
            $table->dateTime('occurred_at');
            $table->primary(['source_type', 'source_id']);     // a record belongs to one contact
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_links');
        Schema::dropIfExists('contacts');
    }
};
