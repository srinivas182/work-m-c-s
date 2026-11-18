<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 20.3: demo photos. A template asset can fill one of the design's photo slots (hero, gallery, team…),
     * with where it came from and under which licence, so a photo can be credited — or swapped everywhere at once.
     */
    public function up(): void
    {
        Schema::table('template_assets', function (Blueprint $table) {
            $table->string('slot', 40)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->string('photographer', 120)->nullable();
            $table->string('licence', 60)->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->string('added_by', 26)->nullable();
            $table->index(['template_id', 'slot']);
        });
    }

    public function down(): void
    {
        Schema::table('template_assets', function (Blueprint $table) {
            $table->dropIndex(['template_id', 'slot']);
            $table->dropColumn(['slot', 'source_url', 'photographer', 'licence', 'ai_generated', 'added_by']);
        });
    }
};
