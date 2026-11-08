<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each customer in the programme: their code, and who referred them (Sprint 13.2).
        Schema::create('referral_members', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id');
            $table->string('code', 12);
            $table->ulid('referrer_id')->nullable()->index(); // another member of this business
            $table->dateTime('referred_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'contact_id']);
            $table->unique(['tenant_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_members');
    }
};
