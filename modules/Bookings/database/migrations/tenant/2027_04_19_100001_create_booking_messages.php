<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each booking message once (confirmed, reminder_24h, reminder_2h, moved_<n>, cancelled), with its outcome.
        Schema::create('booking_messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('booking_id')->index();
            $table->string('kind', 30);
            $table->string('channel', 10)->nullable();   // email | sms | whatsapp
            $table->string('status', 12);                // sending | sent | failed
            $table->string('detail', 200)->nullable();   // e.g. "Fell back to email: not enough credits"
            $table->timestamp('created_at')->nullable();
            $table->unique(['booking_id', 'kind']);
        });

        Schema::table('booking_settings', function (Blueprint $table) {
            $table->string('message_channel', 10)->default('email'); // email (free) | sms | whatsapp
            $table->boolean('remind_24h')->default(true);
            $table->boolean('remind_2h')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('booking_settings', fn (Blueprint $table) => $table->dropColumn(['message_channel', 'remind_24h', 'remind_2h']));
        Schema::dropIfExists('booking_messages');
    }
};
