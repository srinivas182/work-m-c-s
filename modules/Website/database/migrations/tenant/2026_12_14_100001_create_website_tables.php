<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->json('branding'); // owner inputs only; the palette is computed from them (Sprint 0 engine)
            $table->json('menus');
            $table->json('settings');
            $table->timestamps();
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('title', 120);
            $table->string('slug', 80);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_home')->default(false);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->longText('draft');                         // our content document (JSON), never raw editor data
            $table->unsignedInteger('draft_revision')->default(1); // detects two people saving over each other
            $table->ulid('published_version_id')->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'position']);
            $table->index(['tenant_id', 'slug']);
        });

        // Immutable snapshot for every publish.
        Schema::create('page_versions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->ulid('page_id');
            $table->unsignedInteger('number');
            $table->string('title', 120);
            $table->longText('content');
            $table->string('note', 255)->nullable();
            $table->ulid('published_by')->nullable();
            $table->timestamp('created_at');

            $table->unique(['tenant_id', 'page_id', 'number']);
        });
    }

    public function down(): void
    {
        foreach (['page_versions', 'pages', 'sites'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
