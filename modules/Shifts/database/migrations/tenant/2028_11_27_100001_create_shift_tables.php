<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 26.4: the roster, leave and swaps, clocking in and out, commission rates and tips. Not payroll: no tax or
 * UIF — the numbers go to the business's bookkeeper.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id');
            $table->ulid('branch_id')->nullable();
            $table->date('on');
            $table->string('starts', 5);   // HH:MM in the business's time zone
            $table->string('ends', 5);
            $table->ulid('created_by')->nullable();
            $table->datetimes();
            $table->unique(['tenant_id', 'member_id', 'on', 'starts']);
            $table->index(['tenant_id', 'on']);
        });
        Schema::create('shift_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id');
            $table->string('kind', 10);                 // leave | swap
            $table->ulid('shift_id')->nullable();       // swap: the shift to give away
            $table->ulid('swap_with')->nullable();      // swap: the team member taking it
            $table->date('from')->nullable();           // leave
            $table->date('until')->nullable();
            $table->string('note', 300)->nullable();
            $table->string('status', 10)->default('pending'); // pending | approved | declined | cancelled
            $table->ulid('decided_by')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->datetimes();
        });
        Schema::create('clock_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id');
            $table->ulid('branch_id')->nullable();
            $table->dateTime('in_at');
            $table->dateTime('out_at')->nullable();
            $table->string('check', 10)->default('none'); // none | wifi | location — how clocking in was checked
            $table->datetimes();
            $table->index(['tenant_id', 'member_id', 'in_at']);
        });
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->ulid('member_id');
            $table->unsignedSmallInteger('services_bp')->default(0); // basis points: 1500 = 15%
            $table->unsignedSmallInteger('products_bp')->default(0);
            $table->datetimes();
            $table->primary(['tenant_id', 'member_id']);
        });
        Schema::create('tips', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id');
            $table->unsignedInteger('cents');
            $table->string('source_type', 20)->nullable(); // booking | sale
            $table->string('source_id', 40)->nullable();
            $table->ulid('recorded_by')->nullable();
            $table->datetimes();
            $table->index(['tenant_id', 'member_id', 'created_at']);
        });
        Schema::create('shift_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('clock_check', 10)->default('none'); // none | wifi | location (decision C: off by default)
            $table->json('wifi')->nullable();       // the business Wi-Fi's public addresses
            $table->json('places')->nullable();     // [{name, lat, lng}] where clocking in is allowed (within 200 m)
            $table->datetimes();
        });
    }

    public function down(): void
    {
        foreach (['shift_settings', 'tips', 'commission_rates', 'clock_entries', 'shift_requests', 'shifts'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
