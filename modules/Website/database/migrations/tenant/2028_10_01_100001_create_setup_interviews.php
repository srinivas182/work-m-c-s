<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Setting a business up by talking (Sprint 23.1): the owner's answers, in their own words, and what WURK
        // understood from them (the brief), until they confirm.
        Schema::create('setup_interviews', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('person', 40);
            $table->json('answers');
            $table->json('brief');
            $table->string('status', 10)->default('open'); // open | done
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_interviews');
    }
};
