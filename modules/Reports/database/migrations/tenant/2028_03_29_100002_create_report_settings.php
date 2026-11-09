<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A business's VAT registration (Sprint 15.1b) — VAT is reported only once it says it's registered.
        Schema::create('report_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('vat_registered')->default(false);
            $table->string('vat_number', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_settings');
    }
};
