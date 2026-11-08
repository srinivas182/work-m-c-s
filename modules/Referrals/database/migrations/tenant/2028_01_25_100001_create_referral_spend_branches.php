<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which branches' rewards a wallet spend used (Sprint 13.9b) — so a give-back reverses the same settlement.
        Schema::create('referral_spend_branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('source_type', 20);
            $table->string('source_id', 100);
            $table->ulid('earned_branch_id')->nullable();   // where the rewards were earned (null = the main branch)
            $table->ulid('spent_branch_id')->nullable();    // where they were spent
            $table->unsignedInteger('cents');
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_spend_branches');
    }
};
