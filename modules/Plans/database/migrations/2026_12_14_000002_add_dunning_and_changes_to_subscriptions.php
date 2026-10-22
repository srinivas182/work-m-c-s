<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->timestamp('past_due_since')->nullable()->after('current_period_end'); // dunning day 0
            $table->ulid('pending_package_id')->nullable()->after('past_due_since');      // downgrade at renewal
            $table->string('pending_period', 10)->nullable()->after('pending_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_subscriptions', function (Blueprint $table) {
            $table->dropColumn(['past_due_since', 'pending_package_id', 'pending_period']);
        });
    }
};
