<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Sprint 18.4a: when an archived demo's data was removed for good (7 days after it was archived). */
    public function up(): void
    {
        Schema::table('agent_demos', function (Blueprint $table) {
            $table->dateTime('purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agent_demos', fn (Blueprint $t) => $t->dropColumn('purged_at'));
    }
};
