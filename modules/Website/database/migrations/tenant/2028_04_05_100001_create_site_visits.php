<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Website analytics (Sprint 15.2): counted on WURK's servers, no cookies; no IP addresses stored.
        Schema::create('site_page_views', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->date('day');
            $table->string('path', 200);
            $table->unsignedInteger('views')->default(0);
            $table->primary(['tenant_id', 'day', 'path']);
        });
        // One row per visitor per day: a one-way hash with a daily secret that is discarded — not traceable across days.
        Schema::create('site_visitors', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->date('day');
            $table->char('hash', 32);
            $table->string('source', 60);
            $table->primary(['tenant_id', 'day', 'hash']);
        });
        Schema::create('site_conversions', function (Blueprint $table) {
            $table->ulid('tenant_id')->index();
            $table->date('day');
            $table->string('source', 60);
            $table->string('source_type', 20);
            $table->string('source_id', 100);
            $table->primary(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_conversions');
        Schema::dropIfExists('site_visitors');
        Schema::dropIfExists('site_page_views');
    }
};
