<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 17.2: releases. `release_tasks` remembers which version-specific steps (config/release.php) have run here,
     * so each runs once per environment however many deploys follow; `releases` keeps each deploy's outcome (live,
     * rolled back, failed) for Admin → System and the notice to HQ.
     */
    public function up(): void
    {
        Schema::create('release_tasks', function (Blueprint $table) {
            $table->string('key', 191)->primary();          // version + command
            $table->string('version', 20);
            $table->string('command', 160);
            $table->dateTime('ran_at');
            $table->unsignedInteger('duration_ms');
        });
        Schema::create('releases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('version', 40);
            $table->string('environment', 20);
            $table->string('status', 20);                    // live, rolled_back, failed
            $table->string('commit', 40)->nullable();
            $table->string('note', 500)->nullable();
            $table->dateTime('finished_at');
            $table->index(['environment', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('releases');
        Schema::dropIfExists('release_tasks');
    }
};
