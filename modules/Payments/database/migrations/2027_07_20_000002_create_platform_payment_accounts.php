<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WURK's own accounts, for businesses paying WURK invoices (same shape as a business's accounts).
        Schema::create('platform_payment_accounts', function (Blueprint $table) {
            $table->string('provider', 10)->primary();
            $table->string('mode', 5)->default('test');
            $table->text('test_credentials')->nullable();
            $table->text('live_credentials')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->string('webhook_id', 100)->nullable();
            $table->string('label', 60)->nullable();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('platform_payment_attempts', function (Blueprint $table) {
            $table->string('reference', 100)->primary();
            $table->string('provider', 10);
            $table->string('provider_id', 100)->nullable()->index();
            $table->unsignedInteger('amount_cents');
            $table->string('status', 12)->default('pending');
            $table->text('form')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payment_attempts');
        Schema::dropIfExists('platform_payment_accounts');
    }
};
