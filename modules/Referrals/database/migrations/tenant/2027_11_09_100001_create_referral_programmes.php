<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business's referral programme rules (Sprint 13.1). One row per business; off until the owner switches it on.
        Schema::create('referral_programmes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('self_bp')->default(0);            // self-cashback, basis points (1% = 100)
            $table->json('level_bp');                                    // [level 1, level 2, ...] in basis points
            $table->string('attribution', 10)->default('first');        // first (for life) | last
            $table->unsignedSmallInteger('link_days')->default(30);     // how long a referral link counts
            $table->unsignedSmallInteger('return_days')->default(14);   // pending → available after this
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_programmes');
    }
};
