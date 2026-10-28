<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('address', 300)->nullable();
            $table->string('phone', 40)->nullable();
            $table->dateTime('closed_at')->nullable(); // closed branches keep their history; never deleted
        });
    }

    public function down(): void
    {
        Schema::table('branches', fn (Blueprint $table) => $table->dropColumn(['address', 'phone', 'closed_at']));
    }
};
