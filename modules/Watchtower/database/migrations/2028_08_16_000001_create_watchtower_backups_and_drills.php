<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 18.4c: backups as the backup jobs report them (system:backup-taken), and restore drills with their timings
     * against WURK's targets (data lost ≤ 15 minutes, back within 4 hours) — shown on Admin → System; a backup that
     * stops arriving raises a Watchtower problem.
     */
    public function up(): void
    {
        Schema::create('watchtower_backups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('target', 64);                  // central, shard-1, files…
            $table->string('kind', 20);                    // full, incremental, snapshot, files
            $table->dateTime('taken_at');
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('location', 255)->nullable();
            $table->string('recorded_by', 120)->nullable();
            $table->dateTime('created_at');
            $table->index(['target', 'taken_at']);
        });
        Schema::create('watchtower_drills', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('scope', 20);                   // shard, business, everything
            $table->string('target', 120)->nullable();
            $table->dateTime('backup_at')->nullable();     // the point in time restored to
            $table->dateTime('failed_at');                 // when the (pretend) disaster happened
            $table->dateTime('restored_at');               // when it was working again
            $table->unsignedInteger('restore_minutes');
            $table->unsignedInteger('data_loss_minutes')->nullable();
            $table->boolean('passed');
            $table->text('notes')->nullable();
            $table->string('recorded_by', 120)->nullable();
            $table->dateTime('created_at');
            $table->index('restored_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchtower_drills');
        Schema::dropIfExists('watchtower_backups');
    }
};
