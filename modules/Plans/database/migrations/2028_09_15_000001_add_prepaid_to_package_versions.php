<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pay as you go (Sprint 22.5): the price is taken from the business's WURK credits each day, not invoiced.
        Schema::table('package_versions', function (Blueprint $table) {
            $table->boolean('prepaid')->default(false)->after('credit_packs_allowed');
        });
    }

    public function down(): void
    {
        Schema::table('package_versions', function (Blueprint $table) {
            $table->dropColumn('prepaid');
        });
    }
};
