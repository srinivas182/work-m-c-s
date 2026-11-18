<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 21.2: Meta's review status of each template WURK submits on a business's WhatsApp account (the follow-up after
     * the 24-hour window, then reminders with buttons — 21.5), by name: PENDING, APPROVED, REJECTED or PAUSED.
     */
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->json('templates')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('templates');
        });
    }
};
