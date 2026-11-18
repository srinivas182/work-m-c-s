<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 20.2: short videos in the media library, for the video hero and the Video section. A video is re-encoded
     * (H.264, at most 1280 wide and 30 seconds) and keeps a still poster, whose sizes are the row's variants.
     */
    public function up(): void
    {
        Schema::table('site_assets', function (Blueprint $table) {
            $table->string('kind', 8)->default('photo');           // photo | video
            $table->string('video_path', 255)->nullable();          // the MP4
            $table->unsignedInteger('duration_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_assets', function (Blueprint $table) {
            $table->dropColumn(['kind', 'video_path', 'duration_ms']);
        });
    }
};
