<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What can be booked. Lengths and buffers in 5-minute steps (bookings claim 5-minute slots).
        Schema::create('booking_services', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('buffer_before_minutes')->default(0);
            $table->unsignedSmallInteger('buffer_after_minutes')->default(0);
            $table->unsignedInteger('price_cents')->default(0);
            $table->string('deposit_type', 10)->default('none'); // none | fixed | percent (8.3)
            $table->unsignedInteger('deposit_value')->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Which team members do which services.
        Schema::create('booking_service_member', function (Blueprint $table) {
            $table->ulid('service_id');
            $table->ulid('member_id');
            $table->ulid('tenant_id')->index();
            $table->primary(['service_id', 'member_id']);
        });

        // Weekly working hours: one row per working stretch (a break is the gap between two rows).
        Schema::create('booking_hours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id')->index();
            $table->unsignedTinyInteger('weekday'); // 0 = Monday … 6 = Sunday
            $table->time('starts');
            $table->time('ends');
        });

        // Time off for one team member, or the whole business (member_id null: e.g. a public holiday).
        Schema::create('booking_time_off', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('member_id')->nullable()->index();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('reason', 120)->nullable();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('branch_id')->nullable()->index(); // multi-branch arrives in Sprint 11
            $table->ulid('service_id')->index();
            $table->ulid('member_id')->index();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('confirmed'); // pending_payment | confirmed | cancelled | no_show | completed
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('source', 20)->default('owner'); // owner | website
            $table->ulid('created_by')->nullable();
            $table->timestamps();
        });

        // The guarantee: every booking claims each 5-minute slot it occupies (buffers included) per team
        // member. Two overlapping bookings would claim the same slot — the unique key refuses the second,
        // even if both arrive at once.
        Schema::create('booking_slots', function (Blueprint $table) {
            $table->ulid('member_id');
            $table->timestamp('slot_at');
            $table->ulid('booking_id')->index();
            $table->ulid('tenant_id')->index();
            $table->primary(['member_id', 'slot_at']);
        });
    }

    public function down(): void
    {
        foreach (['booking_slots', 'bookings', 'booking_time_off', 'booking_hours', 'booking_service_member', 'booking_services'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
