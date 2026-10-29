<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The business's own courier accounts (WURK never pays couriers). Per business (branch_id null) or per branch.
        Schema::create('shipping_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('branch_id')->nullable();
            $table->string('provider', 20);                       // bobgo | pargo
            $table->string('mode', 10)->default('test');          // test | live
            $table->boolean('active')->default(false);
            $table->text('test_credentials')->nullable();         // encrypted
            $table->text('live_credentials')->nullable();         // encrypted
            $table->unsignedInteger('flat_rate_cents')->nullable(); // Pargo pickup points: a flat rate
            $table->string('label', 120)->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
            $table->unique(['tenant_id', 'provider', 'branch_id']);
        });
        // A shipment for an order: courier, service, tracking and label.
        Schema::create('shipments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('reference', 100)->index();            // e.g. shop-{order id}
            $table->string('provider', 20);
            $table->ulid('account_branch_id')->nullable();         // which account (null = the business's)
            $table->string('service', 120)->nullable();
            $table->string('pickup_point', 60)->nullable();        // Pargo point code
            $table->string('provider_id', 120)->nullable();
            $table->string('tracking_reference', 120)->nullable();
            $table->string('tracking_url', 500)->nullable();
            $table->string('label_url', 500)->nullable();
            $table->string('status', 30)->default('created');      // created | collected | in_transit | delivered | failed
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_accounts');
    }
};
