<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WURK's help centre (Sprint 16.1) — WURK's own content, so central.
        Schema::create('help_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('slug', 80)->unique();
            $table->string('name', 80);
            $table->string('summary', 200)->nullable();
            $table->unsignedSmallInteger('position')->default(100);
            $table->timestamps();
        });
        Schema::create('help_articles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('category_id')->index();
            $table->string('slug', 120)->unique();
            $table->string('title', 150);
            $table->string('summary', 300)->nullable();
            $table->mediumText('body');                       // Markdown, rendered safely
            $table->string('video_path', 200)->nullable();     // a 30-second video on WURK's public storage (no third-party players)
            $table->string('poster_path', 200)->nullable();
            $table->json('screens')->nullable();               // back-office screens it helps with (Inertia page names)
            $table->string('status', 10)->default('draft');    // draft | published
            $table->unsignedSmallInteger('position')->default(100);
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });
        // Searches that found nothing — what to write next. The words only, no person.
        Schema::create('help_searches', function (Blueprint $table) {
            $table->string('query', 120);
            $table->date('day');
            $table->unsignedInteger('count')->default(1);
            $table->primary(['query', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_searches');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('help_categories');
    }
};
