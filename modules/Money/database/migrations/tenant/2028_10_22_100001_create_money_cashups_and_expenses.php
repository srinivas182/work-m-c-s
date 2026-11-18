<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // End-of-day cash-ups (Sprint 23.5b): what the till should hold, what was counted, and why they differ.
        Schema::create('money_cashups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->date('day');
            $table->ulid('branch_id')->nullable();
            $table->json('expected');                            // {cash, card_machine, qr} in cents
            $table->json('counted');
            $table->integer('difference_cents');                 // cash counted − cash expected
            $table->string('reason', 500)->nullable();
            $table->string('status', 10);                        // locked | reopened
            $table->ulid('counted_by');
            $table->string('counted_by_name', 120);
            $table->ulid('reopened_by')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'day', 'branch_id']);
        });
        // Expenses in seconds: amount, category and note, or a photo of the slip read by WURK AI and confirmed.
        Schema::create('money_expenses', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->date('spent_on');
            $table->unsignedInteger('amount_cents');
            $table->string('category', 20);                      // stock | rent | wages | transport | data | other
            $table->string('note', 200)->nullable();
            $table->string('slip_path', 300)->nullable();        // private disk, never public
            $table->boolean('from_slip')->default(false);
            $table->ulid('created_by')->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'spent_on']);
        });
        // A slip photo read by WURK AI, waiting for the owner to confirm it as an expense.
        Schema::create('money_slips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('path', 300);
            $table->json('read');                                // {amount_cents, spent_on, category, note}
            $table->ulid('created_by')->nullable();
            $table->datetimes();
        });
        Schema::create('money_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->unsignedInteger('cashup_reason_above_cents')->default(2000); // a difference above this needs a reason
            $table->boolean('weekly_summary')->default(true);   // Monday's "how did I do" on WhatsApp
            $table->dateTime('weekly_summary_sent_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_settings');
        Schema::dropIfExists('money_slips');
        Schema::dropIfExists('money_expenses');
        Schema::dropIfExists('money_cashups');
    }
};
