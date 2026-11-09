<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owners' summary email (Sprint 15.1b): weekly by default, monthly, or off; never twice for the same period.
        Schema::table('report_settings', function (Blueprint $t) {
            $t->string('summary', 10)->default('weekly');
            $t->date('summary_sent_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('report_settings', fn (Blueprint $t) => $t->dropColumn(['summary', 'summary_sent_for']));
    }
};
