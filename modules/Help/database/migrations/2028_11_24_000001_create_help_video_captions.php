<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 25.6: subtitles for the help centre's 30-second videos. English is written with the video; the other
 * languages are drafted by WURK AI on the same timings and shown only once a language reviewer approves them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_video_captions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('article_id');
            $table->string('locale', 5);
            $table->text('vtt');                                  // WebVTT
            $table->string('status', 10)->default('draft');       // draft | approved
            $table->boolean('drafted_by_ai')->default(false);
            $table->ulid('reviewed_by')->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->datetimes();
            $table->unique(['article_id', 'locale']);
            $table->foreign('article_id')->references('id')->on('help_articles')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('help_video_captions');
    }
};
