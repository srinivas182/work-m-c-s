<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_settings', fn (Blueprint $t) => $t->boolean('health_practice')->default(false));
        // Each practitioner's professional registration (e.g. HPCSA MP0123456), shown to patients.
        Schema::create('member_registrations', function (Blueprint $table) {
            $table->ulid('member_id')->primary();
            $table->ulid('tenant_id')->index();
            $table->string('council', 60);
            $table->string('number', 40);
            $table->timestamps();
        });
        // What the patient agreed to before an online consultation, and when (the wording's version, never rewritten).
        foreach (['bookings', 'class_attendees'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('consent_version', 20)->nullable();
                $table->timestamp('consent_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['bookings', 'class_attendees'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['consent_version', 'consent_at']));
        }
        Schema::dropIfExists('member_registrations');
        Schema::table('booking_settings', fn (Blueprint $t) => $t->dropColumn('health_practice'));
    }
};
