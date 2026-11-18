<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Money taken in person (Sprint 22.3): cash, the card machine, or a QR code at the counter — on a booking,
        // a class place or a shop order — with who took it.
        Schema::create('in_person_payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('source_type', 20);
            $table->string('source_id', 40);
            $table->string('method', 20);
            $table->unsignedBigInteger('amount_cents');
            $table->string('taken_by', 40)->nullable();
            $table->string('taken_by_name', 120);
            $table->ulid('branch_id')->nullable();
            $table->dateTime('taken_at');
            $table->datetimes();
            $table->index(['tenant_id', 'taken_at']);
            $table->index(['tenant_id', 'source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_person_payments');
    }
};
