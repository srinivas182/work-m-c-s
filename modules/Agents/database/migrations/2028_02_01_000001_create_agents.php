<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WURK's agents and their sponsor hierarchy (Sprint 14.1) — central: agents work across businesses.
        Schema::create('agents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('identity_id')->unique();               // their WURK ID
            $table->string('code', 12)->unique();                // their referral code
            $table->ulid('sponsor_id')->nullable()->index();     // the agent above them
            $table->string('status', 10)->default('active');     // active | suspended
            $table->unsignedSmallInteger('commission_months')->nullable(); // null = lifetime; else N months from each business's start
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
