<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 18.4a: every move of a business between shards, and every restore of one business from a backup — what was
     * copied, whether the copies matched, and when the old copy may be removed. Plus when an archived business's data
     * was removed for good.
     */
    public function up(): void
    {
        Schema::create('tenant_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('kind', 10);                         // move | restore
            $table->string('source', 120);                      // a shard id, or the backup connection's name
            $table->foreignUlid('from_shard_id')->nullable()->constrained('shards')->nullOnDelete();
            $table->foreignUlid('to_shard_id')->constrained('shards')->restrictOnDelete();
            $table->string('status', 12);                       // copying | done | failed | cleared
            $table->json('rows')->nullable();                   // table => rows copied
            $table->string('error', 500)->nullable();
            $table->ulid('started_by')->nullable();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->dateTime('clear_after')->nullable();        // the old copy (on the shard it left) is removed after this
            $table->index(['status', 'clear_after']);
        });
        Schema::table('tenants', function (Blueprint $table) {
            $table->dateTime('purged_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', fn (Blueprint $t) => $t->dropColumn('purged_at'));
        Schema::dropIfExists('tenant_moves');
    }
};
