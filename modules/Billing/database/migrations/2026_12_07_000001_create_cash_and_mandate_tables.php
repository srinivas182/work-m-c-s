<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cash at a WURK office: recorded by one person, approved by code sent to others.
        Schema::create('cash_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->ulid('recorded_by');
            $table->string('payer_name', 120);
            $table->string('receipt_number', 40);
            $table->string('notes', 500)->nullable();
            $table->json('approvers'); // who the code was sent to
            $table->char('code_hash', 64);
            $table->timestamp('code_expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 20)->default('awaiting_approval'); // awaiting_approval | approved | cancelled
            $table->ulid('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // DebiCheck mandates. Account and ID numbers are encrypted; only a masked account is shown.
        Schema::create('mandates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('reference', 40)->unique();
            $table->string('provider_mandate_id', 80)->nullable()->index();
            $table->string('status', 30)->default('pending_authentication'); // pending_authentication | active | rejected | cancelled
            $table->string('bank', 40);
            $table->string('account_type', 20);
            $table->string('account_masked', 20);
            $table->string('holder_name', 120);
            $table->text('secret'); // encrypted JSON: account number, branch code, ID number
            $table->unsignedTinyInteger('collection_day');
            $table->unsignedInteger('max_amount_cents');
            $table->string('failure', 255)->nullable();
            $table->timestamps();
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->string('collection_method', 20)->default('card')->after('default_method_id'); // card | debit_order
            $table->ulid('default_mandate_id')->nullable()->after('collection_method');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn(['collection_method', 'default_mandate_id']);
        });
        Schema::dropIfExists('mandates');
        Schema::dropIfExists('cash_payments');
    }
};
