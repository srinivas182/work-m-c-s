<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sprint 21.5: the message a customer's WhatsApp starts with when they tap the website's button or scan the QR code. */
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('chat_text', 160)->nullable()->after('templates');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('chat_text');
        });
    }
};
