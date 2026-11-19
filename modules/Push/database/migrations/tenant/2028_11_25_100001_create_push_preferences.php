<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Sprint 26.2: what each person has switched off in this business (everything is on until they do). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_preferences', function (Blueprint $table) {
            $table->ulid('tenant_id');
            $table->ulid('identity_id');
            $table->json('off');
            $table->datetimes();
            $table->primary(['tenant_id', 'identity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_preferences');
    }
};
