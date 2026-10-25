<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central list of bookings holding a time while their deposit is paid, so the every-minute
        // sweep finds expired holds without visiting every business's shard.
        Schema::create('booking_holds', function (Blueprint $table) {
            $table->string('reference', 100)->primary();
            $table->ulid('tenant_id')->index();
            $table->timestamp('expires_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_holds');
    }
};
