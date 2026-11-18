<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Translations of WURK's fixed texts (Sprint 24.2): drafted by WURK AI, reviewed by people. They add to and
        // override lang/{code}.json, so reviewers' corrections take effect without a release.
        Schema::create('translations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('locale', 5);
            $table->char('source_hash', 40);                     // sha1 of the English text
            $table->text('source');
            $table->text('text');
            $table->string('status', 10);                        // draft | reviewed
            $table->ulid('updated_by')->nullable();
            $table->datetimes();
            $table->unique(['locale', 'source_hash']);
            $table->index(['locale', 'status']);
        });
        // Words each language always translates the same way (booking, deposit, invoice…), given to WURK AI.
        Schema::create('translation_glossary', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('locale', 5);
            $table->string('term', 120);
            $table->string('translation', 200);
            $table->string('note', 300)->nullable();
            $table->datetimes();
            $table->unique(['locale', 'term']);
        });
        // Which languages are offered (a language can be switched off while it's being prepared).
        Schema::create('translation_languages', function (Blueprint $table) {
            $table->string('locale', 5)->primary();
            $table->boolean('enabled')->default(true);
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_languages');
        Schema::dropIfExists('translation_glossary');
        Schema::dropIfExists('translations');
    }
};
