<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recently deleted (Sprint 16.6c): a deleted customer can be put back for 30 days; after that their details are
     * erased (as for a customer's own request) and erased_at is set, so it happens once.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable();
            $table->timestamp('erased_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['deleted_at', 'erased_at']));
    }
};
