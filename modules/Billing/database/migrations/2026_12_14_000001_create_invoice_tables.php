<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Gapless numbering: the next number is taken under a row lock inside the
        // transaction that creates the document, so a failure never leaves a gap.
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('name', 30)->primary();
            $table->unsignedBigInteger('next');
        });
        DB::table('document_sequences')->insert([['name' => 'invoice', 'next' => 1], ['name' => 'credit_note', 'next' => 1]]);

        // Tax invoices are immutable once issued; corrections are credit notes.
        Schema::create('invoices', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 30)->unique();
            $table->foreignUlid('tenant_id')->constrained('tenants');
            $table->foreignUlid('payment_id')->nullable()->unique()->constrained('payments');
            $table->timestamp('issued_at');
            $table->json('issuer');   // name, address, VAT number at the time of issue
            $table->json('customer'); // billing name, email, address, VAT number at the time of issue
            $table->char('currency', 3);
            $table->unsignedSmallInteger('vat_bp');
            $table->unsignedInteger('excl_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->unsignedInteger('credited_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('description', 255);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('excl_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
        });

        Schema::create('credit_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('number', 30)->unique();
            $table->foreignUlid('invoice_id')->constrained('invoices');
            $table->foreignUlid('tenant_id')->constrained('tenants');
            $table->timestamp('issued_at');
            $table->string('reason', 255);
            $table->unsignedInteger('excl_cents');
            $table->unsignedInteger('vat_cents');
            $table->unsignedInteger('total_cents');
            $table->string('refund', 20); // card | eft | none
            $table->string('refund_status', 20)->default('pending'); // pending | done | not_needed
            $table->ulid('issued_by')->nullable();
            $table->timestamps();
        });

        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->string('billing_phone', 20)->nullable()->after('billing_email');
            $table->string('address', 255)->nullable()->after('billing_name');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropColumn(['billing_phone', 'address']);
        });
        foreach (['credit_notes', 'invoice_lines', 'invoices', 'document_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
