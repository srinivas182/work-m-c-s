<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('category_key', 40)->nullable()->after('tenant_id');   // fixed after signup
            $table->string('template_key', 60)->nullable()->after('category_key');
            $table->unsignedInteger('template_version')->nullable()->after('template_key');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['category_key', 'template_key', 'template_version']);
        });
    }
};
