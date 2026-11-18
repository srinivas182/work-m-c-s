<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simple invoices (Sprint 23.5a): lines, VAT when the business is VAT-registered, a due date and a pay link.
        Schema::create('money_invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->unsignedInteger('number');
            $table->string('customer_name', 120);
            $table->string('customer_phone', 30)->nullable();
            $table->string('customer_email', 255)->nullable();
            $table->json('lines');                               // [{description, quantity, unit_cents}]
            $table->unsignedSmallInteger('vat_bp')->default(0);  // 1500 = 15% (0 when not VAT-registered)
            $table->string('vat_number', 30)->nullable();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('status', 12);                        // draft | sent | paid | void
            $table->string('payment_reference', 80)->nullable()->unique();
            $table->dateTime('sent_at')->nullable();
            $table->string('sent_by', 10)->nullable();           // whatsapp | sms | email | link
            $table->dateTime('paid_at')->nullable();
            $table->string('paid_how', 20)->nullable();          // online | cash | card_machine | eft | qr
            $table->unsignedTinyInteger('reminders_sent')->default(0);
            $table->dateTime('last_reminded_at')->nullable();
            $table->boolean('reminders_on')->default(true);
            $table->ulid('created_by')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'due_on']);
        });
        // Customers who replied STOP to payment reminders (per phone number, per business).
        Schema::create('money_reminder_stops', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->string('phone', 30);
            $table->dateTime('stopped_at');
            $table->primary(['tenant_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('money_reminder_stops');
        Schema::dropIfExists('money_invoices');
    }
};
