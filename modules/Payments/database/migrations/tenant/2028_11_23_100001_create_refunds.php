<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every refund, however it was paid back (Sprint 25.5): through the payment provider, cash, the card machine, a QR
 * app, EFT, mobile money or store credit — with why, who did it and the note the customer was sent. Cash-up counts
 * refunds paid back at the counter as money out on the day they're paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('kind', 20); // order | sale | invoice | booking | class
            $table->string('source_id', 40);
            $table->unsignedBigInteger('amount_cents');
            $table->string('method', 20);
            $table->string('reason', 200);
            $table->text('note')->nullable(); // what the customer was told
            $table->ulid('by')->nullable();
            $table->string('by_name', 120);
            $table->ulid('branch_id')->nullable();
            $table->dateTime('refunded_at');
            $table->string('told_by', 20)->nullable(); // sms | whatsapp | email | null
            $table->datetimes();
            $table->index(['tenant_id', 'kind', 'source_id']);
            $table->index(['tenant_id', 'refunded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
