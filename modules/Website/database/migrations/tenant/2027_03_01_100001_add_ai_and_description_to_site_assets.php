<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_assets', function (Blueprint $table) {
            // AI images are decorative only: the server refuses them in sections that show real work or people.
            $table->boolean('ai_generated')->default(false)->after('variants');
            // Default description (alt text) offered wherever the photo is used.
            $table->string('description', 200)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('site_assets', function (Blueprint $table) {
            $table->dropColumn(['ai_generated', 'description']);
        });
    }
};
