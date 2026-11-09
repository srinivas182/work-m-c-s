<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agents' demo sites (Sprint 14.8): one active per agent; 14 days; reminders on days 10 and 13; then archived.
        Schema::create('agent_demos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id')->index();
            $table->ulid('tenant_id')->unique();
            $table->string('template', 60);
            $table->dateTime('expires_at');
            $table->dateTime('reminded_10_at')->nullable();
            $table->dateTime('reminded_13_at')->nullable();
            $table->dateTime('archived_at')->nullable();
            $table->dateTime('claimed_at')->nullable();   // 14.8b
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_demos');
    }
};
