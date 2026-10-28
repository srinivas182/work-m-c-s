<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each team member's own Google Meet or Zoom link (the business default stays on meeting_connections).
        Schema::create('member_meeting_links', function (Blueprint $table) {
            $table->ulid('member_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('url', 500);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_meeting_links');
    }
};
