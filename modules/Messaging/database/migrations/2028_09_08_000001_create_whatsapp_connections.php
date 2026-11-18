<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 21.1: a business's own WhatsApp number, connected through Meta's Embedded Signup. Central, because Meta's
     * webhooks arrive for all businesses at one address and are routed by phone number ID.
     */
    public function up(): void
    {
        Schema::create('whatsapp_connections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->unique();
            $table->string('waba_id', 40);
            $table->string('phone_number_id', 40)->unique();
            $table->string('display_phone', 40)->nullable();
            $table->string('verified_name', 160)->nullable();
            $table->string('status', 20)->default('connected'); // connected | disconnected
            $table->boolean('coexistence')->default(false);     // the owner also uses the WhatsApp Business app
            $table->string('name_status', 40)->nullable();       // APPROVED, PENDING_REVIEW, DECLINED…
            $table->string('quality', 20)->nullable();           // GREEN | YELLOW | RED
            $table->string('messaging_limit', 40)->nullable();
            $table->boolean('payment_method')->nullable();      // Meta needs one on the account before messaging
            $table->text('token');                              // encrypted business token; never sent to the browser
            $table->text('pin')->nullable();                    // encrypted two-step verification PIN (new numbers only)
            $table->ulid('connected_by')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('disconnected_at')->nullable();
            $table->dateTime('checked_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_connections');
    }
};
