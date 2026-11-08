<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each purchase that earned rewards (once per source: a resent payment notice can't pay twice).
        Schema::create('referral_purchases', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('source_type', 20);
            $table->ulid('source_id');
            $table->ulid('member_id');                         // the buyer
            $table->unsignedInteger('paid_cents');
            $table->unsignedInteger('commissionable_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->ulid('branch_id')->nullable();
            $table->dateTime('occurred_at');
            $table->unique(['tenant_id', 'source_type', 'source_id']);
        });
        // The rewards ledger: append-only. Earned lines become available after the return window; refunds add
        // reversing (negative) lines. Nothing is ever edited — pending vs available is derived from the dates.
        Schema::create('referral_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id')->index();               // who earns (or loses) it
            $table->ulid('purchase_id')->nullable()->index();
            $table->unsignedTinyInteger('level');             // 0 = the buyer's own cashback; 1..7 = referrers
            $table->string('kind', 12);                       // earned | reversed
            $table->integer('cents');                         // + earned, − reversed
            $table->unsignedInteger('bp')->nullable();        // the rate applied
            $table->dateTime('available_at');
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_ledger');
        Schema::dropIfExists('referral_purchases');
    }
};
