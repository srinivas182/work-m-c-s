<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A person's portal access at this business (Sprint 13.6a): their WURK ID, linked to their customer record.
        Schema::create('portal_customers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('identity_id');
            $table->ulid('contact_id');
            $table->dateTime('consented_at')->nullable();   // POPIA: agreed on first sign-in
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'identity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_customers');
    }
};
