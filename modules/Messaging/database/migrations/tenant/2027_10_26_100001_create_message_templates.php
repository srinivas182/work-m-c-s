<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A business's own wording for a customer message (email or SMS). No row = the standard wording.
        Schema::create('message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('key', 60);        // e.g. booking.confirmed
            $table->string('channel', 10);    // email | sms (WhatsApp is fixed: Meta-approved)
            $table->string('subject', 120)->nullable();
            $table->string('text', 2000);
            $table->string('updated_by', 120)->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['tenant_id', 'key', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
