<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_rollouts', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->string('mode', 20)->default('off'); // off | opt_in | percentage | on
            $table->unsignedTinyInteger('percentage')->default(0);
            $table->timestamp('default_on_at')->nullable(); // opt-in window end: on for everyone after this
            $table->ulid('updated_by')->nullable();
            $table->timestamps();
        });

        // Kept apart from Pennant's cached values so clearing a flag never loses a business's choice.
        Schema::create('feature_opt_ins', function (Blueprint $table) {
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('key', 80);
            $table->ulid('by')->nullable();
            $table->timestamps();

            $table->primary(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_opt_ins');
        Schema::dropIfExists('feature_rollouts');
    }
};
