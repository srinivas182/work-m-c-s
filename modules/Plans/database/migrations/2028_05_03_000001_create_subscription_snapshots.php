<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // WURK's SaaS metrics (Sprint 15.4): each business's subscription as it stood each day — MRR movement and
        // cohorts are worked out from these. History starts the day this is deployed.
        Schema::create('subscription_snapshots', function (Blueprint $table) {
            $table->date('day');
            $table->ulid('tenant_id');
            $table->string('status', 20);          // trial | pending_payment | active | past_due | suspended
            $table->ulid('package_id')->nullable();
            $table->string('period', 10)->nullable();
            $table->unsignedInteger('mrr_cents');  // excl. VAT; 0 unless paying (active or past_due)
            $table->char('country', 2);
            $table->primary(['day', 'tenant_id']);
            $table->index(['tenant_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_snapshots');
    }
};
