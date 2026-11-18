<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 21.2: Meta's review status of the "follow-up" template WURK submits on each business's WhatsApp account. After
     * the 24-hour window closes, the team can still reach a customer with it once Meta approves it.
     */
    public function up(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->string('follow_up_status', 20)->nullable()->after('payment_method'); // PENDING | APPROVED | REJECTED | PAUSED | …
        });
    }

    public function down(): void
    {
        Schema::table('whatsapp_connections', function (Blueprint $table) {
            $table->dropColumn('follow_up_status');
        });
    }
};
