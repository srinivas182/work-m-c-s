<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 19.5: WURK's own sales assistant on wurk.co.za. Visitors are told the conversation is kept for 30 days;
     * one that ended with a hand-over is kept with the message it sent. No business owns these: they're WURK's.
     */
    public function up(): void
    {
        Schema::create('sales_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('visitor', 64)->index();          // a hash of the browser's own random key
            $table->json('items');                           // the conversation, provider-neutral (the last 40 items)
            $table->unsignedInteger('replies')->default(0);
            $table->string('outcome', 16)->nullable();       // answered | signup | handed_over
            $table->dateTime('last_message_at')->index();
            $table->dateTimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_conversations');
    }
};
