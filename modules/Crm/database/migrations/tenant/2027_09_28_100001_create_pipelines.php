<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipelines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('pipeline_id')->index();
            $table->string('name', 60);
            $table->string('kind', 10)->default('open');     // open | won | lost — whatever the stage is called
            $table->unsignedSmallInteger('position')->default(0);
        });
        Schema::create('leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id')->index();
            $table->ulid('pipeline_id')->index();
            $table->ulid('stage_id')->index();
            $table->string('title', 160);
            $table->unsignedBigInteger('value_cents')->nullable();
            $table->string('source', 20)->default('manual');  // manual | form | chat | quote
            $table->ulid('assigned_to')->nullable()->index();  // team member
            $table->ulid('branch_id')->nullable()->index();
            $table->dateTime('follow_up_at')->nullable()->index();
            $table->dateTime('reminded_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        // A lead's history: stage moves, assignments, follow-ups and notes.
        Schema::create('lead_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('lead_id')->index();
            $table->string('event', 20);                       // created | moved | assigned | follow_up | note
            $table->string('detail', 1000)->nullable();
            $table->string('by', 120)->nullable();
            $table->dateTime('at');
        });
    }

    public function down(): void
    {
        foreach (['lead_events', 'leads', 'pipeline_stages', 'pipelines'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
