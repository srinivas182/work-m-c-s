<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('phone', 20)->nullable()->after('email');
            $table->index(['phone', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropIndex(['phone', 'kind']);
            $table->dropColumn('phone');
        });
    }
};
