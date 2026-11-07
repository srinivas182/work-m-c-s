<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // My WURK's index (Sprint 13.8): only "this WURK ID has an account at this business" — no balances or details,
        // which stay in each business's own database.
        Schema::create('customer_memberships', function (Blueprint $table) {
            $table->ulid('identity_id');
            $table->ulid('tenant_id')->index();
            $table->dateTime('hidden_at')->nullable();   // removed from My WURK by the customer
            $table->dateTime('created_at');
            $table->primary(['identity_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_memberships');
    }
};
