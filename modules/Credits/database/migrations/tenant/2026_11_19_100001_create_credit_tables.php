<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Append-only. Amounts in thousandths of a credit; debits are negative.
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('type', 20); // allowance | purchase | adjustment | debit | refund | expiry
            $table->bigInteger('amount_milli');
            $table->bigInteger('balance_after_milli');
            $table->string('idempotency_key', 120);
            $table->string('channel', 20)->nullable();
            $table->string('description', 255);
            $table->ulid('created_by')->nullable();
            $table->timestamp('created_at', 6);

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('credit_balances', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->bigInteger('balance_milli')->default(0);
            $table->bigInteger('allowance_milli')->default(0); // part of the balance that expires at the next allowance
            $table->bigInteger('low_balance_milli')->default(20000); // alert threshold (20 credits)
            $table->timestamp('low_alerted_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_balances');
        Schema::dropIfExists('credit_ledger');
    }
};
