<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Refunding a paid invoice (Sprint 25.5): how much of it has gone back. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('money_invoices', function (Blueprint $table) {
            $table->unsignedBigInteger('refunded_cents')->default(0)->after('total_cents');
        });
    }

    public function down(): void
    {
        Schema::table('money_invoices', function (Blueprint $table) {
            $table->dropColumn('refunded_cents');
        });
    }
};
