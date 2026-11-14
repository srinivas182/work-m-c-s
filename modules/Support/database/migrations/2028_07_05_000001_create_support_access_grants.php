<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Consent-based support access (Sprint 16.4): asked from a ticket, approved by the business, time-limited. */
    public function up(): void
    {
        Schema::create('support_access_grants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('ticket_id')->index();
            $table->ulid('tenant_id');
            $table->ulid('staff_id');
            $table->string('requested_level', 4);              // view | full
            $table->string('reason', 500);
            $table->string('status', 10)->default('requested'); // requested | active | declined | ended
            $table->string('level', 4)->nullable();            // what the owner allowed
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->ulid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->ulid('ended_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'staff_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_access_grants');
    }
};
