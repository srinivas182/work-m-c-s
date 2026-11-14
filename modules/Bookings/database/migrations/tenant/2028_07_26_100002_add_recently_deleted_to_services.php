<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Recently deleted (Sprint 16.6b): services can be deleted, and put back for 30 days. */
    public function up(): void
    {
        Schema::table('booking_services', fn (Blueprint $table) => $table->timestamp('deleted_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('booking_services', fn (Blueprint $table) => $table->dropColumn('deleted_at'));
    }
};
