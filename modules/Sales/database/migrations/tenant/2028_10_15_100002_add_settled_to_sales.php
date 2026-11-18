<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A sale on account (Sprint 23.5a): the customer pays later; settled when they do, and how.
        Schema::table('sales', function (Blueprint $table) {
            $table->dateTime('settled_at')->nullable();
            $table->string('settled_how', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['settled_at', 'settled_how']);
        });
    }
};
