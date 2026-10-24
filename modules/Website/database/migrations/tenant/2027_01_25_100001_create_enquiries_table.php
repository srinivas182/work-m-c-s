<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quote requests from the website (until the CRM, Sprint 12). Only what the
        // visitor typed and agreed to share; the IP address is stored only as a hash.
        Schema::create('enquiries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('tenant_id');
            $table->string('form', 20)->default('quote');
            $table->string('name', 80);
            $table->string('phone', 20)->nullable();
            $table->string('email', 120)->nullable();
            $table->string('service', 80)->nullable();
            $table->text('message');
            $table->timestamp('consented_at');
            $table->char('ip_hash', 64);
            $table->string('status', 20)->default('new'); // new | read | archived
            $table->timestamp('created_at');

            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
