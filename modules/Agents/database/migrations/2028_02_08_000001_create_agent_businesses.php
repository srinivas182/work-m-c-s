<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which agent brought which business (Sprint 14.2) — central, beside the agents.
        Schema::create('agent_businesses', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->ulid('agent_id')->index();
            $table->string('source', 10);              // link | staff
            $table->dateTime('attributed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_businesses');
    }
};
