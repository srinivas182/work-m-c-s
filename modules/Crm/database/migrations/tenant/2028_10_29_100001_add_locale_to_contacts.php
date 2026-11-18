<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The customer's language (Sprint 24.2): from the page they used, or set by the business. Messages follow it.
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('locale', 5)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
