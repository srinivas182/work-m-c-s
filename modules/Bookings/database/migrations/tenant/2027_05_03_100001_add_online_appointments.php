<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_services', function (Blueprint $table) {
            $table->string('location_type', 12)->default('in_person'); // in_person | online | either
        });
        Schema::table('bookings', function (Blueprint $table) {
            $table->boolean('online')->default(false);
            $table->string('meeting_provider', 10)->nullable(); // google | zoom | fixed
            $table->string('meeting_id', 200)->nullable();
            $table->string('meeting_url', 500)->nullable();
        });
        // The business's own video account (one per business). Tokens are encrypted at rest.
        Schema::create('meeting_connections', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('provider', 10); // google | zoom | fixed
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('account_label', 160)->nullable(); // e.g. the connected email address
            $table->string('fixed_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_connections');
        Schema::table('bookings', fn (Blueprint $table) => $table->dropColumn(['online', 'meeting_provider', 'meeting_id', 'meeting_url']));
        Schema::table('booking_services', fn (Blueprint $table) => $table->dropColumn('location_type'));
    }
};
