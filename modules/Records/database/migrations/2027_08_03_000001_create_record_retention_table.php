<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central list of when each patient's retention runs from, so the daily check visits only businesses with records due.
        Schema::create('record_retention', function (Blueprint $table) {
            $table->ulid('patient_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->timestamp('last_document_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_retention');
    }
};
