<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What each order, booking or sale was paid (Sprint 15.1a) — from the purchase notices, for lifetime value.
        // Joined to contact_links by (source_type, source_id), whichever arrives first.
        Schema::create('contact_purchases', function (Blueprint $table) {
            $table->ulid('tenant_id')->index();
            $table->string('source_type', 20);
            $table->string('source_id', 100);
            $table->unsignedInteger('paid_cents');
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->dateTime('created_at');
            $table->primary(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_purchases');
    }
};
