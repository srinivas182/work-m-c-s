<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Off → upgrading is the only way to get more credits (e.g. trial or free packages).
        Schema::table('package_versions', function (Blueprint $table) {
            $table->boolean('credit_packs_allowed')->default(true)->after('monthly_credits');
        });
    }

    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('credit_packs_allowed');
        });
    }
};
