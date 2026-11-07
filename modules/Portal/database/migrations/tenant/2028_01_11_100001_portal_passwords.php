<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How customers sign in (Sprint 13.6b): code only (default), or code or password.
        Schema::create('portal_settings', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->boolean('passwords_allowed')->default(false);
        });
        // Default passwords (decision J): per business, hashed, single-use, valid 7 days — never on the WURK ID.
        Schema::create('portal_default_passwords', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id')->index();
            $table->ulid('contact_id')->index();
            $table->string('destination', 160);   // the normalised email or number it was sent to
            $table->string('hash');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['tenant_id', 'destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_default_passwords');
        Schema::dropIfExists('portal_settings');
    }
};
