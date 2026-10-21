<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('handle', 40)->unique();
            $table->string('name');
            $table->string('status', 20)->default('provisioning')->index();
            $table->string('database_model', 20)->default('shared');
            $table->foreignUlid('shard_id')->constrained('shards')->restrictOnDelete();
            $table->char('country', 2)->default('ZA');
            $table->string('timezone', 64)->default('Africa/Johannesburg');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
