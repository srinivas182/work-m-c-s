<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 26.2: the phones that get WURK's push notifications (one row per phone and browser, per person), and WURK's
 * own VAPID key pair, generated once by WURK (the private key encrypted with APP_KEY).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('identity_id')->index();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('device', 120)->nullable();
            $table->dateTime('last_sent_at')->nullable();
            $table->datetimes();
        });
        Schema::create('push_vapid_keys', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('public_key', 100);   // base64url, uncompressed P-256 point
            $table->text('private_key');          // PEM, encrypted
            $table->datetimes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_vapid_keys');
        Schema::dropIfExists('push_subscriptions');
    }
};
