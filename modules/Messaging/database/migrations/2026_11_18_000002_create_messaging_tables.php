<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per channel ("sms", "whatsapp", "email", "payments.payfast", …).
        Schema::create('provider_settings', function (Blueprint $table) {
            $table->string('channel', 40)->primary();
            $table->string('driver', 40);
            $table->text('credentials'); // encrypted JSON; never sent to the browser
            $table->json('settings')->nullable(); // non-secret: sender id, phone number id…
            $table->boolean('active')->default(false);
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_test_ok')->nullable();
            $table->string('last_test_message', 500)->nullable();
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('message_deliveries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->nullable()->index();
            $table->string('channel', 20);
            $table->string('driver', 40);
            $table->char('to_hash', 64)->index();
            $table->string('to_masked', 40);
            $table->string('purpose', 40);
            $table->string('status', 20); // sent | delivered | failed
            $table->string('provider_message_id', 120)->nullable()->index();
            $table->string('error', 500)->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deliveries');
        Schema::dropIfExists('provider_settings');
    }
};
