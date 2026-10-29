<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What one branch owes another: money received into one branch's account for something another supplied.
        // Records only — settled by hand; no money moves through WURK.
        Schema::create('branch_settlements', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('from_branch_id');                // received the money (owes)
            $table->ulid('to_branch_id');                  // supplied (is owed)
            $table->integer('amount_cents');               // negative for refunds
            $table->string('kind', 20);                    // order | deposit | class | refund
            $table->string('reference', 100);
            $table->string('note', 200);
            $table->dateTime('occurred_at')->index();
            $table->dateTime('settled_at')->nullable();
            $table->string('settled_by', 120)->nullable();
            $table->index(['from_branch_id', 'to_branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_settlements');
    }
};
