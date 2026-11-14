<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Support tickets (Sprint 16.3) — WURK's own records of talking to businesses, so central: WURK's inbox sees every
     * business's tickets in one place. Screenshots are kept on the private disk.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->unsignedInteger('number')->unique();       // shown as #1001
            $table->ulid('tenant_id')->index();
            $table->ulid('opened_by');                         // identity
            $table->string('subject', 150);
            $table->string('status', 10)->default('open');    // open (with WURK) | pending (with the business) | solved
            $table->string('priority', 10)->default('normal'); // low | normal | high | urgent
            $table->ulid('assignee_id')->nullable()->index();  // WURK staff (16.3b)
            $table->string('package_key', 40)->nullable();
            $table->unsignedSmallInteger('target_hours');      // first reply, business hours (decision B)
            $table->timestamp('first_response_due_at');
            $table->timestamp('first_responded_at')->nullable();
            $table->string('page', 200)->nullable();           // where they were
            $table->string('browser', 300)->nullable();
            $table->string('last_error', 500)->nullable();
            $table->string('rating', 4)->nullable();           // good | bad
            $table->string('rating_comment', 500)->nullable();
            $table->timestamp('solved_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'first_response_due_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('ticket_id')->index();
            $table->string('author_type', 10);                 // business | staff
            $table->ulid('author_id');
            $table->text('body');
            $table->boolean('internal')->default(false);       // staff-only notes (16.3b)
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('support_attachments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('ticket_id')->index();
            $table->ulid('message_id')->index();
            $table->string('path', 200);
            $table->string('name', 120);
            $table->unsignedInteger('size');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
