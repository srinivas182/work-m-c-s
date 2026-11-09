<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform-wide credit usage (Sprint 15.4b): rolled up nightly from each business's own ledger, so WURK's
        // metrics never read every business's database. Backfilled once from the ledgers' history.
        Schema::create('credit_usage_daily', function (Blueprint $table) {
            $table->date('day');
            $table->ulid('tenant_id');
            $table->string('channel', 20);
            $table->unsignedBigInteger('used_milli');
            $table->primary(['day', 'tenant_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_usage_daily');
    }
};
