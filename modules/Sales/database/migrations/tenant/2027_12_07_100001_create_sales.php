<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-store sales (Sprint 13.5): linked to the customer, so they count for rewards and history.
        Schema::create('sales', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id')->nullable()->index();
            $table->ulid('branch_id')->nullable()->index();
            $table->unsignedInteger('amount_cents');
            $table->string('method', 10);                       // cash | card | eft
            $table->string('note', 200)->nullable();
            $table->string('status', 10);                       // recorded | pending | declined
            $table->ulid('recorded_by');
            $table->string('recorded_by_name', 120);
            $table->string('device', 120)->nullable();          // which device it was recorded on (fraud controls)
            $table->ulid('decided_by')->nullable();
            $table->string('decided_by_name', 120)->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->unsignedInteger('refunded_cents')->default(0);
            $table->dateTime('recorded_at');
            $table->index(['tenant_id', 'status']);
        });
        // The amount above which a sale needs approval (0 = never).
        Schema::create('sales_preferences', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->unsignedInteger('approval_above_cents')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_preferences');
        Schema::dropIfExists('sales');
    }
};
