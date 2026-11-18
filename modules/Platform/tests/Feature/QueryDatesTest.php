<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Dates bound to queries are compared the way they're stored: wall-clock time in the app's zone. Before the fix, a
 * range given in UTC compared two hours out, so a sale at 23:30 in Johannesburg fell out of that day's totals.
 */
beforeEach(function () {
    $this->zone = date_default_timezone_get();
    Schema::create('query_dates_probe', function (Blueprint $t) {
        $t->id();
        $t->dateTime('at');
    });
});

afterEach(function () {
    Schema::dropIfExists('query_dates_probe');
    date_default_timezone_set($this->zone);
});

it('finds a late-evening row with a day range given in UTC, in the business zone or in the app zone', function () {
    config(['app.timezone' => 'Africa/Johannesburg']);
    date_default_timezone_set('Africa/Johannesburg');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 23:30:00', 'Africa/Johannesburg'));
    DB::table('query_dates_probe')->insert(['at' => now()]); // stored as 2026-11-18 23:30:00

    $day = CarbonImmutable::parse('2026-11-18', 'Africa/Johannesburg');
    foreach ([$day, $day->utc(), $day->setTimezone('Africa/Lagos')] as $from) {
        $count = DB::table('query_dates_probe')->where('at', '>=', $from)->where('at', '<', $from->addDay())->count();
        expect($count)->toBe(1, 'from '.$from->toIso8601String());
    }
    expect(DB::table('query_dates_probe')->where('at', '>=', $day->addDay()->utc())->count())->toBe(0); // the next day starts at 22:00 UTC
});

it('leaves dates given as text as they are', function () {
    DB::table('query_dates_probe')->insert(['at' => '2026-11-18 23:30:00']);
    expect(DB::table('query_dates_probe')->where('at', '>=', '2026-11-18 23:00:00')->count())->toBe(1)
        ->and(DB::table('query_dates_probe')->where('at', '>=', '2026-11-18 23:45:00')->count())->toBe(0);
});
