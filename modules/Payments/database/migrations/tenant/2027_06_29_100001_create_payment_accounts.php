<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business's own merchant accounts. Credentials are encrypted and never shown again.
        Schema::create('payment_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('provider', 10);          // payfast | yoco
            $table->string('mode', 5)->default('test'); // test | live
            $table->text('credentials');             // encrypted JSON
            $table->text('webhook_secret')->nullable(); // encrypted (Yoco)
            $table->string('webhook_id', 100)->nullable();
            $table->string('label', 60)->nullable(); // e.g. "Merchant ••••0100" — safe to show
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'provider']);
        });

        // Every payment started: which provider, how much, and what the provider calls it.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->string('reference', 100)->primary();
            $table->ulid('tenant_id')->index();
            $table->string('provider', 10);
            $table->string('provider_id', 100)->nullable()->index(); // Yoco checkout id / PayFast pf_payment_id
            $table->unsignedInteger('amount_cents');
            $table->string('status', 12)->default('pending'); // pending | paid | refunded
            $table->json('form')->nullable();                  // PayFast: the signed fields the customer's browser posts
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_accounts');
    }
};
