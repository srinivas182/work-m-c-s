<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Wizard progress, resumable for 7 days after verification.
        Schema::create('signups', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('fingerprint', 64)->index(); // the contact being verified (hashed)
            $table->string('step', 20);
            $table->text('data'); // encrypted JSON: names, contact, package, address…
            $table->timestamp('verified_at')->nullable();
            $table->ulid('tenant_id')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // One free trial per verified email address or mobile number.
        Schema::create('trial_claims', function (Blueprint $table) {
            $table->char('fingerprint', 64)->primary();
            $table->ulid('tenant_id');
            $table->timestamp('claimed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_claims');
        Schema::dropIfExists('signups');
    }
};
