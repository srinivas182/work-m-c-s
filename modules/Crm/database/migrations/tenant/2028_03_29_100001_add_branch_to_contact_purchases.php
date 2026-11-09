<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Takings per branch (Sprint 15.1b: the takings and VAT report).
        Schema::table('contact_purchases', fn (Blueprint $t) => $t->ulid('branch_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('contact_purchases', fn (Blueprint $t) => $t->dropColumn('branch_id'));
    }
};
