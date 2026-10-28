<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Tables whose rows now belong to a branch (existing rows move to their business's main branch). */
    private const TABLES = ['booking_hours', 'bookings', 'class_sessions', 'reservations'];

    public function up(): void
    {
        foreach (self::TABLES as $t) {
            if (! Schema::hasColumn($t, 'branch_id')) { // bookings, classes and reservations already have it (planned in Sprint 8, unused)
                Schema::table($t, fn (Blueprint $table) => $table->ulid('branch_id')->nullable()->index());
            }
            // Every existing row → its business's main branch (the branch scope hides rows without a branch from branch managers).
            DB::table($t)->update(['branch_id' => DB::raw("(select b.id from branches b where b.tenant_id = {$t}.tenant_id and b.is_default = 1 limit 1)")]);
        }
        // Which branches offer a service (none listed = every branch), and an optional price per branch.
        Schema::create('booking_service_branch', function (Blueprint $table) {
            $table->ulid('service_id');
            $table->ulid('branch_id');
            $table->ulid('tenant_id')->index();
            $table->unsignedInteger('price_cents')->nullable(); // null = the service's own price
            $table->primary(['service_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_service_branch');
        Schema::table('booking_hours', fn (Blueprint $table) => $table->dropColumn('branch_id')); // the only one this migration added
    }
};
