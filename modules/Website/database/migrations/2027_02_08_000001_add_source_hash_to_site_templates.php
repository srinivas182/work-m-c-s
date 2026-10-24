<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fingerprint of the repository file a template was installed from: a new version
        // is published only when the file actually changes.
        Schema::table('site_templates', function (Blueprint $table) {
            $table->char('source_hash', 64)->nullable()->after('published_version');
        });
    }

    public function down(): void
    {
        Schema::table('site_templates', function (Blueprint $table) {
            $table->dropColumn('source_hash');
        });
    }
};
