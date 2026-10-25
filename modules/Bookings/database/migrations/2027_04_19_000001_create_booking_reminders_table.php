<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Central list of reminders due, so the 5-minute sweep finds them without visiting every shard.
        Schema::create('booking_reminders', function (Blueprint $table) {
            $table->ulid('booking_id');
            $table->string('kind', 30); // reminder_24h_<start> | reminder_2h_<start>
            $table->ulid('tenant_id')->index();
            $table->timestamp('due_at')->index();
            $table->primary(['booking_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
    }
};
