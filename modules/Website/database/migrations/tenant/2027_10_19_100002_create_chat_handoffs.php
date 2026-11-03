<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Decision A: only chats that reach a person are stored — with consent, kept with the contact.
        Schema::create('chat_handoffs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 80);
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('message', 1000)->nullable();
            $table->json('transcript');                    // the conversation up to the hand-off
            $table->ulid('contact_id')->nullable()->index();
            $table->ulid('lead_id')->nullable();
            $table->dateTime('marketing_consent_at')->nullable();
            $table->string('status', 10)->default('new');  // new | read | archived
            $table->string('ip_hash', 64);
            $table->dateTime('created_at')->nullable();
        });
        // Anonymous counts of which buttons are used (no visitor details), so owners can improve the answers.
        Schema::create('chat_taps', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->date('day');
            $table->string('label', 30);
            $table->unsignedInteger('taps')->default(0);
            $table->primary(['tenant_id', 'day', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_taps');
        Schema::dropIfExists('chat_handoffs');
    }
};
