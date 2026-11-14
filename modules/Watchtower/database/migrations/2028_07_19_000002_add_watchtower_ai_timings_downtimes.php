<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Watchtower 18.0b: the day each problem was explained by the AI (for the daily cap); sampled response times per
     * route per hour (slow pages against the 300 ms budget); and downtime reported by the external uptime checker.
     */
    public function up(): void
    {
        Schema::table('watchtower_incidents', function (Blueprint $table) {
            $table->date('analysed_on')->nullable()->index();
        });
        Schema::create('watchtower_timings', function (Blueprint $table) {
            $table->string('route', 200);                 // "GET {tenant}/bookings"
            $table->dateTime('hour');
            $table->unsignedInteger('samples')->default(0);
            $table->unsignedInteger('le300')->default(0);  // sampled requests answered within 300 ms…
            $table->unsignedInteger('le1000')->default(0);
            $table->unsignedInteger('le2000')->default(0);
            $table->unsignedInteger('slow')->default(0);   // every request over 2 s (not sampled)
            $table->unsignedInteger('max_ms')->default(0);
            $table->boolean('evaluated')->default(false);
            $table->primary(['route', 'hour']);
            $table->index(['evaluated', 'hour']);
        });
        Schema::create('watchtower_downtimes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('monitor', 160);
            $table->string('cause', 255)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->index(['monitor', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watchtower_downtimes');
        Schema::dropIfExists('watchtower_timings');
        Schema::table('watchtower_incidents', fn (Blueprint $t) => $t->dropColumn('analysed_on'));
    }
};
