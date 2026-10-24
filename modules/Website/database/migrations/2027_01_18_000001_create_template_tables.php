<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_categories', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('key', 40)->unique();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            $table->string('icon', 40)->default('store');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('site_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('site_categories');
            $table->string('key', 60)->unique();
            $table->string('name', 80);
            $table->string('description', 240)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('status', 20)->default('draft'); // draft | published | retired
            $table->longText('draft');                      // definition being edited (pages, menus, branding)
            $table->unsignedInteger('draft_revision')->default(1);
            $table->unsignedInteger('published_version')->nullable();
            $table->timestamps();
        });

        // What businesses receive: immutable published definitions.
        Schema::create('site_template_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('template_id')->constrained('site_templates')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->longText('definition');
            $table->string('note', 255)->nullable();
            $table->ulid('published_by')->nullable();
            $table->timestamp('created_at');

            $table->unique(['template_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_template_versions');
        Schema::dropIfExists('site_templates');
        Schema::dropIfExists('site_categories');
    }
};
