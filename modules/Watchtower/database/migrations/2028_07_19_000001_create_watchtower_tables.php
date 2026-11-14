<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Watchtower (Sprint 18.0): everything that goes wrong on the platform, grouped into incidents by fingerprint
     * (so a thousand identical errors are one problem and one email), plus scheduled-task heartbeats.
     * Central: WURK HQ watches every business. Nothing personal is stored — messages and traces are masked.
     */
    public function up(): void
    {
        Schema::create('watchtower_incidents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('fingerprint', 40)->unique();
            $table->string('kind', 16);                       // exception | frontend | job | scheduler | queue | check | downtime | slow
            $table->string('severity', 10);                   // critical | high | normal
            $table->string('status', 12)->default('new');     // new | acknowledged | fixed | resolved | ignored
            $table->string('title', 255);
            $table->string('exception_class', 255)->nullable();
            $table->text('message')->nullable();              // masked
            $table->string('file', 255)->nullable();          // relative to the app
            $table->unsignedInteger('line')->nullable();
            $table->string('location', 255)->nullable();      // "GET {tenant}/contacts/{contact}", "job Wurk\…\SendX", "command success:score"
            $table->string('surface', 12)->nullable();        // app | admin | my | help | site | console | queue
            $table->text('trace')->nullable();                // top frames, masked, no arguments
            $table->json('context')->nullable();
            $table->unsignedBigInteger('occurrences')->default(0);
            $table->unsignedInteger('businesses')->default(0);
            $table->dateTime('hour_bucket')->nullable();      // occurrences in the current hour, for spikes and severity
            $table->unsignedInteger('hour_count')->default(0);
            $table->string('first_release', 40)->nullable();
            $table->string('last_release', 40)->nullable();
            $table->boolean('ongoing')->default(false);       // checks and backlogs: still failing right now
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->dateTime('alerted_at')->nullable();
            $table->string('alerted_severity', 10)->nullable();
            $table->unsignedBigInteger('alerted_occurrences')->default(0);
            $table->dateTime('regressed_at')->nullable();
            $table->string('fixed_in', 40)->nullable();
            $table->dateTime('fixed_at')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->json('analysis')->nullable();             // the AI's summary, cause and fix (18.0b)
            $table->timestamps();
            $table->index(['status', 'severity', 'last_seen_at']);
            $table->index('last_seen_at');
        });
        Schema::create('watchtower_incident_days', function (Blueprint $table) {
            $table->ulid('incident_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->primary(['incident_id', 'day']);
        });
        Schema::create('watchtower_incident_businesses', function (Blueprint $table) {
            $table->ulid('incident_id');
            $table->ulid('tenant_id');
            $table->primary(['incident_id', 'tenant_id']);
        });
        Schema::create('watchtower_heartbeats', function (Blueprint $table) {
            $table->string('task', 120)->primary();           // "success:score"
            $table->string('expression', 60);
            $table->string('timezone', 40)->nullable();
            $table->dateTime('last_started_at')->nullable();
            $table->dateTime('last_finished_at')->nullable();
            $table->string('last_status', 10)->nullable();   // running | ok | failed
            $table->unsignedInteger('last_runtime_ms')->nullable();
        });
        Schema::create('watchtower_checks', function (Blueprint $table) {
            $table->string('name', 80)->primary();            // "database", "shard:shard_1", "storage", "queue:default"
            $table->boolean('ok');
            $table->string('message', 255)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->dateTime('checked_at');
            $table->dateTime('failing_since')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['watchtower_checks', 'watchtower_heartbeats', 'watchtower_incident_businesses', 'watchtower_incident_days', 'watchtower_incidents'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
