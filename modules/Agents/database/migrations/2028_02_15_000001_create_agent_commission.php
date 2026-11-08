<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The percentages (decision A): self, then each level up — set by WURK's super admin; 0% until set.
        Schema::create('agent_commission_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // a single row
            $table->json('level_bp');                     // [self, level 1, ... level 7] in basis points
            $table->timestamps();
        });
        // Agents' commission (Sprint 14.3): append-only, in cents; once per payment per level.
        Schema::create('agent_ledger', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('agent_id')->index();
            $table->ulid('tenant_id')->nullable()->index();
            $table->ulid('payment_id')->nullable();
            $table->unsignedTinyInteger('level')->nullable(); // 0 = the business's own agent; 1..7 = sponsors above
            $table->string('kind', 12);                       // earned | reversed | paid_out
            $table->integer('cents');
            $table->unsignedInteger('bp')->nullable();
            $table->dateTime('available_at');
            $table->string('reference', 100)->nullable();
            $table->dateTime('created_at');
            $table->unique(['payment_id', 'agent_id', 'level', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ledger');
        Schema::dropIfExists('agent_commission_settings');
    }
};
