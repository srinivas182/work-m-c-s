<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where an agent's commission is paid (Sprint 14.7). The account number is encrypted; only its last four are readable.
        Schema::create('agent_bank_accounts', function (Blueprint $table) {
            $table->ulid('agent_id')->primary();
            $table->string('holder', 120);
            $table->string('bank', 60);
            $table->string('branch_code', 10);
            $table->string('account_type', 12);   // cheque | savings | transmission
            $table->text('account_encrypted');
            $table->char('account_last4', 4);
            $table->dateTime('changed_at');       // a change shortly before a payout batch holds that payout (14.5)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_bank_accounts');
    }
};
