<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 26.5: the business's loyalty card, each customer's card, the purchases that earned stamps, and rewards. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('on')->default(false);
            $table->string('reward', 80);                       // what the customer sees: "your 8th haircut free"
            $table->string('kind', 10)->default('percent');     // percent | amount
            $table->unsignedInteger('value');                   // basis points (10000 = free) or cents off
            $table->unsignedInteger('max_cents')->nullable();   // a percentage reward's most (e.g. one haircut)
            $table->unsignedSmallInteger('stamps')->default(8);
            $table->unsignedInteger('min_spend_cents')->default(0);
            $table->json('counts');                             // booking, order, sale
            $table->datetimes();
        });
        Schema::create('loyalty_cards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id');
            $table->unsignedSmallInteger('stamps')->default(0);
            $table->datetimes();
            $table->unique(['tenant_id', 'contact_id']);
        });
        Schema::create('loyalty_purchases', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->string('source_type', 20);
            $table->string('source_id', 40);
            $table->ulid('card_id');
            $table->unsignedInteger('paid_cents')->default(0);  // everything paid for it so far (a deposit, then the rest in cash)
            $table->boolean('stamped')->default(false);
            $table->datetimes();
            $table->primary(['tenant_id', 'source_type', 'source_id']);
        });
        Schema::create('loyalty_rewards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('card_id')->index();
            $table->string('status', 10)->default('ready');     // ready | used
            $table->string('used_on_type', 20)->nullable();
            $table->string('used_on_id', 40)->nullable();
            $table->unsignedInteger('discount_cents')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->datetimes();
        });
    }

    public function down(): void
    {
        foreach (['loyalty_rewards', 'loyalty_purchases', 'loyalty_cards', 'loyalty_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
