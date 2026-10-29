<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An account belongs to a branch, or (branch_id null) to the whole business.
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->ulid('branch_id')->nullable()->index();
            $table->dropUnique(['tenant_id', 'provider']);
            $table->unique(['tenant_id', 'provider', 'branch_id']);
        });
        // Which account a payment went through (so it's verified and refunded through the same one).
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->ulid('branch_id')->nullable());
    }

    public function down(): void
    {
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('branch_id'));
        Schema::table('payment_accounts', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'provider', 'branch_id']);
            $table->dropColumn('branch_id');
            $table->unique(['tenant_id', 'provider']);
        });
    }
};
