<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Sprint 22.5, pay as you go: the last day the plan was taken from credits, the day credits ran out (the grace
        // period counts from it), and the last "N days left" warning sent.
        Schema::table('billing_accounts', function (Blueprint $t) {
            $t->date('prepaid_charged_on')->nullable();
            $t->date('prepaid_ran_out_on')->nullable();
            $t->unsignedTinyInteger('prepaid_warned_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', fn (Blueprint $t) => $t->dropColumn(['prepaid_charged_on', 'prepaid_ran_out_on', 'prepaid_warned_days']));
    }
};
