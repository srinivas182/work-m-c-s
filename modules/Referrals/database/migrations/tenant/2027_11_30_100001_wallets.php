<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wallet rules (Sprint 13.4b), set by the owner.
        Schema::table('referral_programmes', function (Blueprint $table) {
            $table->boolean('payouts_allowed')->default(false);
            $table->unsignedInteger('min_payout_cents')->default(10000);   // R100
            $table->unsignedInteger('max_wallet_bp')->default(5000);       // the most of an order a wallet may pay (50%)
            $table->unsignedSmallInteger('expiry_months')->default(12);    // 0 = never
        });
        // Wallet movements in the same append-only ledger: spent (−), given back on a refund (+), expired (−), paid out (−).
        Schema::table('referral_ledger', function (Blueprint $table) {
            $table->string('source_type', 20)->nullable();
            $table->ulid('source_id')->nullable();
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::table('referral_ledger', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'source_type', 'source_id']);
            $table->dropColumn(['source_type', 'source_id']);
        });
        Schema::table('referral_programmes', fn (Blueprint $table) => $table->dropColumn(['payouts_allowed', 'min_payout_cents', 'max_wallet_bp', 'expiry_months']));
    }
};
