<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The whole site (pages, menus, branding) before a template switch, so it can be undone.
        Schema::create('site_snapshots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('reason', 40);
            $table->string('template_key', 60)->nullable();
            $table->longText('snapshot');
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_snapshots');
    }
};
