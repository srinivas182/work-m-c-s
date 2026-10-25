<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Group classes: one scheduled session, many attendees, a fixed capacity.
        Schema::create('class_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('branch_id')->nullable()->index();
            $table->string('title', 120);
            $table->string('description', 500)->nullable();
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at');
            $table->unsignedSmallInteger('capacity');
            $table->string('location_type', 12)->default('in_person'); // in_person | online
            $table->ulid('member_id')->nullable();                      // the instructor
            $table->unsignedInteger('price_cents')->default(0);         // shown; paid at the class
            $table->string('meeting_provider', 10)->nullable();
            $table->string('meeting_id', 200)->nullable();
            $table->string('meeting_url', 500)->nullable();
            $table->string('status', 12)->default('scheduled');         // scheduled | cancelled
            $table->timestamps();
        });

        Schema::create('class_attendees', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('session_id')->index();
            $table->string('status', 12)->default('confirmed');         // confirmed | cancelled | attended | no_show
            $table->string('customer_name', 120);
            $table->string('customer_phone', 40)->nullable();
            $table->string('customer_email', 160)->nullable();
            $table->string('source', 20)->default('owner');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_attendees');
        Schema::dropIfExists('class_sessions');
    }
};
