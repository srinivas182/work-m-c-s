<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 21.2: WhatsApp conversations in Chats. The customer's last message opens WhatsApp's 24-hour window for free-form
     * replies; each message keeps WhatsApp's own ID (repeats are ignored), its delivery status and any file.
     */
    public function up(): void
    {
        Schema::table('receptionist_conversations', function (Blueprint $table) {
            $table->dateTime('last_inbound_at')->nullable();
        });
        Schema::table('receptionist_messages', function (Blueprint $table) {
            $table->string('external_id', 120)->nullable();
            $table->string('delivery', 12)->nullable();     // sent | delivered | read | failed
            $table->string('delivery_error', 300)->nullable();
            $table->json('attachment')->nullable();         // {path, mime, name, bytes, kind} once fetched and scanned
            $table->unique(['tenant_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('receptionist_messages', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_id']);
            $table->dropColumn(['external_id', 'delivery', 'delivery_error', 'attachment']);
        });
        Schema::table('receptionist_conversations', function (Blueprint $table) {
            $table->dropColumn('last_inbound_at');
        });
    }
};
