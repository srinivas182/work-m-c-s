<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business health (Sprint 16.5): each business's latest score — worked out nightly, so the page stays fast at any
     * number of businesses — and server errors counted per business per day.
     */
    public function up(): void
    {
        Schema::create('business_health', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('name', 120);
            $table->string('package_key', 40)->nullable();
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('previous_score')->nullable();
            $table->string('grade', 8);                  // healthy | watch | at_risk
            $table->json('signals');                     // [{key, points, reason, action}]
            $table->unsignedInteger('mrr_cents')->default(0);
            $table->timestamp('at_risk_since')->nullable();
            $table->timestamp('computed_at');
            $table->index(['grade', 'mrr_cents']);
            $table->index('score');
        });
        Schema::create('business_errors_daily', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(0);
            $table->primary(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_errors_daily');
        Schema::dropIfExists('business_health');
    }
};
