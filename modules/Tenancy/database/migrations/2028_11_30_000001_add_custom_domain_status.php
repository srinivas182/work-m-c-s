<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 17.3: owners' own domains. A custom domain is `pending` until it points at WURK and its certificate is
     * issued, then `active`; it becomes `lapsed` when it stops pointing at WURK (the business's wurk.co.za address keeps
     * working). `provider_id` is Cloudflare's custom hostname; `problem` is what the owner should fix, in plain words.
     */
    public function up(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('type');
            $table->string('provider_id', 64)->nullable()->after('status');
            $table->string('ssl_status', 40)->nullable()->after('provider_id');
            $table->string('problem', 300)->nullable()->after('ssl_status');
            $table->dateTime('checked_at')->nullable()->after('verified_at');
            $table->index(['type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropIndex(['type', 'status']);
            $table->dropColumn(['status', 'provider_id', 'ssl_status', 'problem', 'checked_at']);
        });
    }
};
