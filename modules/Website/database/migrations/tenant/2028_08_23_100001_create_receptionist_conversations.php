<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 19.2: the AI receptionist's conversations. Unlike the rule-based chat (only hand-offs are stored), an AI
     * conversation is kept while it runs and for 30 days after — the customer is told when it starts — so the owner
     * can see what was said and take over. One that led to a booking, an order or a hand-off is kept with the customer.
     */
    public function up(): void
    {
        Schema::create('receptionist_conversations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('channel', 12)->default('web');          // web | whatsapp (Sprint 21)
            $table->string('visitor', 64)->index();                  // a hash of the browser's (or phone's) own key
            $table->string('name', 80)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->ulid('contact_id')->nullable()->index();
            $table->string('status', 12)->default('ai');             // ai | waiting (handed over) | person (taken over) | closed
            $table->string('outcome', 12)->nullable();               // booked | ordered | handed_over | answered
            $table->string('language', 12)->nullable();
            $table->unsignedInteger('replies')->default(0);          // AI replies (each charged)
            $table->unsignedInteger('credits_milli')->default(0);
            $table->boolean('keep')->default(false);                 // led to a booking, order or hand-off
            $table->dateTime('last_message_at');
            $table->dateTimes();
        });
        Schema::create('receptionist_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('conversation_id');
            $table->unsignedInteger('position');                     // order within the conversation
            $table->string('role', 10);                              // user | assistant | tool | person (the owner or their team)
            $table->json('content');                                 // text, or a tool call / result
            $table->dateTime('created_at');
            $table->unique(['conversation_id', 'position']);
            $table->foreign('conversation_id')->references('id')->on('receptionist_conversations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receptionist_messages');
        Schema::dropIfExists('receptionist_conversations');
    }
};
