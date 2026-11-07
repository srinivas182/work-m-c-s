<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Customers' payout requests (Sprint 13.7). The account number is encrypted, and deleted once paid or declined.
        Schema::create('referral_payouts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id')->index();
            $table->unsignedInteger('cents');
            $table->string('status', 10);                      // requested | paid | declined
            $table->string('account_holder', 120);
            $table->string('bank', 80);
            $table->text('account_number')->nullable();        // encrypted; null once paid or declined
            $table->string('account_last4', 4);
            $table->string('branch_code', 12)->nullable();
            $table->string('reference', 80)->nullable();       // the business's payment reference
            $table->string('reason', 500)->nullable();         // when declined
            $table->string('decided_by_name', 120)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_payouts');
    }
};
