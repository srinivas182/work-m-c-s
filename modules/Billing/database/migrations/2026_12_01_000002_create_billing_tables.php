<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_accounts', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->primary()->constrained('tenants')->cascadeOnDelete();
            $table->string('billing_email');
            $table->string('billing_name')->nullable();
            $table->string('vat_number', 20)->nullable();
            $table->ulid('default_method_id')->nullable();
            $table->timestamps();
        });

        // Saved cards: the gateway's reusable authorisation, never card numbers.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->text('authorization'); // encrypted
            $table->string('signature', 120); // stable per physical card: no duplicates
            $table->string('brand', 30)->nullable();
            $table->string('last4', 4)->nullable();
            $table->string('expires', 7)->nullable(); // MM/YYYY
            $table->timestamps();

            $table->unique(['tenant_id', 'gateway', 'signature']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('purpose', 20); // subscription | credits | card_check
            $table->string('reference', 64)->unique();
            $table->string('gateway', 20);
            $table->string('status', 20)->default('pending'); // pending | succeeded | failed | refunded
            $table->char('currency', 3);
            $table->unsignedInteger('amount_excl_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedSmallInteger('vat_bp');
            $table->json('details'); // what was bought: package/period, credit pack…
            $table->ulid('method_id')->nullable();
            $table->string('gateway_id', 64)->nullable();
            $table->string('failure', 255)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });

        // Webhook replay protection: each provider event is handled once.
        Schema::create('gateway_events', function (Blueprint $table) {
            $table->string('id', 120)->primary();
            $table->string('gateway', 20);
            $table->string('type', 60);
            $table->timestamp('received_at');
        });

        Schema::create('credit_packs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 60);
            $table->unsignedInteger('credits');
            $table->unsignedInteger('price_cents'); // excl. VAT
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(100);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['credit_packs', 'gateway_events', 'payments', 'payment_methods', 'billing_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
