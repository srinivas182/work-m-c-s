<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Restaurant tables by covers: many parties share one capacity (no table plan yet).
        Schema::create('reservation_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('enabled')->default(false);
            $table->unsignedSmallInteger('covers')->default(40);          // guests seated at any one time
            $table->unsignedSmallInteger('sitting_minutes')->default(90);
            $table->unsignedSmallInteger('step_minutes')->default(30);    // times offered every …
            $table->unsignedSmallInteger('max_party')->default(8);
            $table->unsignedSmallInteger('notice_minutes')->default(60);
            $table->timestamps();
        });

        Schema::create('reservation_hours', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->unsignedTinyInteger('weekday'); // 0 = Monday … 6 = Sunday
            $table->time('starts');                 // first arrival
            $table->time('ends');                   // last arrival
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('branch_id')->nullable()->index();
            $table->unsignedSmallInteger('party_size');
            $table->date('service_date')->index();  // the evening it belongs to (local), locked while booking
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->string('status', 20)->default('confirmed'); // confirmed | seated | no_show | cancelled
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('notes', 1000)->nullable();
            $table->string('source', 20)->default('owner');
            $table->timestamps();
        });

        // One row per business per day, locked while a reservation is checked and saved, so two
        // parties can never both take the last seats.
        Schema::create('reservation_days', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->date('service_date');
            $table->primary(['tenant_id', 'service_date']);
        });
    }

    public function down(): void
    {
        foreach (['reservation_days', 'reservations', 'reservation_hours', 'reservation_settings'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
