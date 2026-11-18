<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An agent setting a business up on their phone (Sprint 23.4): the owner's verified number, package, setup,
        // first top-up and hand-over. Timed from start to hand-over (the "15 minutes" goal).
        Schema::create('agent_onboardings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id')->index();
            $table->string('step', 20);
            $table->json('data');
            $table->ulid('tenant_id')->nullable()->unique();
            $table->ulid('owner_identity_id')->nullable();
            $table->dateTime('verified_at')->nullable();
            $table->dateTime('handed_over_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_onboardings');
    }
};
