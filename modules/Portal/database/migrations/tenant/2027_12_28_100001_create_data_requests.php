<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // POPIA deletion requests from the portal (Sprint 13.6e). No personal details here: the customer is found by id.
        Schema::create('data_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id')->index();
            $table->ulid('identity_id');
            $table->string('kind', 12);                         // deletion
            $table->string('status', 12);                       // open | completed | declined
            $table->string('reason', 500)->nullable();          // when declined
            $table->string('decided_by_name', 120)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_requests');
    }
};
