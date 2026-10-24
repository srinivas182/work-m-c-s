<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->bigInteger('monthly_limit_milli')->nullable()->after('low_balance_milli'); // null = no limit
            $table->json('staff_limits')->nullable()->after('monthly_limit_milli');             // identity id => milli per month
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->dropColumn(['monthly_limit_milli', 'staff_limits']);
        });
    }
};
