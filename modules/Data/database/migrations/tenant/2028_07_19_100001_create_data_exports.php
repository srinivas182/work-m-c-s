<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Your data" (Sprint 16.6): full exports — built in the background, downloadable for 24 hours, then deleted. */
    public function up(): void
    {
        Schema::create('data_exports', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('requested_by');
            $table->string('status', 10)->default('queued'); // queued | ready | failed | expired
            $table->string('path', 200)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->json('contents')->nullable();             // {tables: {label: rows}, files: {label: count}}
            $table->string('error', 300)->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('downloads')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_exports');
    }
};
