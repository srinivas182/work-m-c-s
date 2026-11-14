<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Ask WURK" (Sprint 16.2): each question asked, and each change it proposed (with what it takes to undo it). */
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('user_id')->index();
            $table->string('question', 500);
            $table->text('answer')->nullable();
            $table->json('sources')->nullable();          // help articles: [{title, url}]
            $table->string('tool', 60)->nullable();       // the data looked at or the change proposed
            $table->boolean('free')->default(true);       // within the month's free questions
            $table->unsignedInteger('charged_milli')->default(0);
            $table->string('status', 12)->default('answered'); // answered | failed
            $table->timestamp('created_at')->nullable();
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('assistant_actions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('question_id')->nullable()->index();
            $table->ulid('user_id')->index();
            $table->string('tool', 60);
            $table->json('params');
            $table->string('summary', 300);
            $table->json('changes');
            $table->string('status', 10)->default('proposed'); // proposed | applied | undone | cancelled
            $table->json('undo')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_actions');
        Schema::dropIfExists('assistant_questions');
    }
};
