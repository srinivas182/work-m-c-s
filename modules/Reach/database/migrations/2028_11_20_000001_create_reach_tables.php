<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Reach (Sprint 25.2): one WURK USSD code for every business, each business with its own short number inside it,
 * and USSD sessions (the menu's place, so a dropped session can be picked up again for two minutes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reach_numbers', function (Blueprint $table) {
            $table->ulid('tenant_id')->primary();
            $table->string('number', 6)->unique(); // dialled after WURK's code, e.g. *384*1234#  → 1234
            $table->boolean('ussd_on')->default(true);
            $table->boolean('sms_on')->default(true);
            $table->string('sms_keyword', 20)->nullable()->unique(); // 25.2b: the business's own word, e.g. GLOW
            $table->datetimes();
        });

        Schema::create('ussd_sessions', function (Blueprint $table) {
            $table->string('id', 100)->primary(); // the provider's session id
            $table->string('provider', 30);
            $table->string('phone', 20)->index();
            $table->ulid('tenant_id')->nullable();
            $table->json('state'); // where in the menu, and what's been chosen
            $table->unsignedSmallInteger('steps')->default(0);
            $table->boolean('ended')->default(false);
            $table->boolean('charged')->default(false);
            $table->datetimes();
            $table->index(['phone', 'updated_at']);
        });

        // A USSD session's price in credits, per country (HQ changes it in Admin → Credits). A starting South Africa rate.
        if (Schema::hasTable('credit_rates')) {
            DB::table('credit_rates')->insert(['id' => (string) Str::ulid(), 'country' => 'ZA', 'channel' => 'ussd', 'credits_milli' => 2000, 'effective_from' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ussd_sessions');
        Schema::dropIfExists('reach_numbers');
        if (Schema::hasTable('credit_rates')) {
            DB::table('credit_rates')->where('channel', 'ussd')->delete();
        }
    }
};
