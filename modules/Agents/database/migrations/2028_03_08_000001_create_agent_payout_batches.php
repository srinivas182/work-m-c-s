<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WURK finance's minimum payout (Sprint 14.5): no minimum until set.
        Schema::table('agent_commission_settings', function (Blueprint $table) {
            $table->unsignedInteger('min_payout_cents')->default(0);
        });
        // Room for 'payout_returned' (a cancelled or bounced payout) — 12 characters was too few.
        Schema::table('agent_ledger', function (Blueprint $table) {
            $table->string('kind', 20)->change();
        });
        // EFT payout batches (Sprint 14.5).
        Schema::create('agent_payout_batches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('reference', 30)->unique();       // WURK-AG-YYYYMMDD-NNN — on bank statements
            $table->string('status', 10);                    // ready | paid | cancelled
            $table->unsignedInteger('count');
            $table->unsignedBigInteger('total_cents');
            $table->ulid('created_by')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });
        Schema::create('agent_payout_items', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('batch_id')->index();
            $table->ulid('agent_id')->index();
            $table->unsignedInteger('cents');
            $table->string('holder', 120);
            $table->string('bank', 60);
            $table->string('branch_code', 10);
            $table->string('account_type', 12);
            $table->char('account_last4', 4);                // the full number is read only for the CSV
            $table->string('status', 10)->default('ready');  // ready | paid | bounced
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_payout_items');
        Schema::dropIfExists('agent_payout_batches');
        Schema::table('agent_commission_settings', fn (Blueprint $table) => $table->dropColumn('min_payout_cents'));
        Schema::table('agent_ledger', fn (Blueprint $table) => $table->string('kind', 12)->change());
    }
};
