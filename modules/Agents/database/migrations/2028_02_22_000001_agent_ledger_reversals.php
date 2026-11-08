<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Several reversals per payment (partial credit notes; a chargeback): once per event, keyed by the reference (Sprint 14.4).
        Schema::table('agent_ledger', function (Blueprint $table) {
            $table->dropUnique(['payment_id', 'agent_id', 'level', 'kind']);
            $table->unique(['payment_id', 'agent_id', 'level', 'kind', 'reference'], 'agent_ledger_once');
        });
    }

    public function down(): void
    {
        Schema::table('agent_ledger', function (Blueprint $table) {
            $table->dropUnique('agent_ledger_once');
            $table->unique(['payment_id', 'agent_id', 'level', 'kind']);
        });
    }
};
