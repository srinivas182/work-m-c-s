<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            // always | online — e.g. paid upfront for online consultations, at the desk in person.
            $table->string('deposit_when', 10)->default('always');
        });
    }

    public function down(): void
    {
        Schema::table('booking_services', fn (Blueprint $table) => $table->dropColumn('deposit_when'));
    }
};
