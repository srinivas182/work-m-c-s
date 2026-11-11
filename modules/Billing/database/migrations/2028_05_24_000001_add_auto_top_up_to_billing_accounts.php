<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sprint 15.5b: auto top-up (a chosen pack, with the saved card, when credits run low; a monthly cap) and the
        // monthly usage summary email (on by default).
        Schema::table('billing_accounts', function (Blueprint $t) {
            $t->boolean('auto_top_up')->default(false);
            $t->ulid('auto_top_up_pack_id')->nullable();
            $t->unsignedInteger('auto_top_up_cap_cents')->nullable(); // per calendar month, excl. VAT
            $t->boolean('usage_summary')->default(true);
            $t->date('usage_summary_for')->nullable();                // the month last summarised
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', fn (Blueprint $t) => $t->dropColumn(['auto_top_up', 'auto_top_up_pack_id', 'auto_top_up_cap_cents', 'usage_summary', 'usage_summary_for']));
    }
};
