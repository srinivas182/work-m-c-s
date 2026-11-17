<?php

declare(strict_types=1);

/*
 * Sprint 18.4b: MySQL's TIMESTAMP can't hold dates after 19 January 2038. Every existing TIMESTAMP column was changed to
 * DATETIME (2028_08_09 migrations); new migrations must use datetime(), datetimes() and softDeletesDatetime().
 */

const DATES_PAST_2038_FROM = '2028_08_09_100001_dates_past_2038.php';

it('creates no TIMESTAMP columns in migrations after the 2038 fix', function () {
    $root = dirname(__DIR__, 2);
    $files = [...glob($root.'/database/migrations/*.php') ?: [], ...glob($root.'/modules/*/database/migrations/*.php') ?: [], ...glob($root.'/modules/*/database/migrations/tenant/*.php') ?: []];
    $offending = [];
    foreach ($files as $file) {
        if (strcmp(basename($file), DATES_PAST_2038_FROM) <= 0) {
            continue;
        }
        if (preg_match('/->\s*(timestamps?|timestampTz|timestampsTz|nullableTimestamps|softDeletes|softDeletesTz)\s*\(/', (string) file_get_contents($file), $m)) {
            $offending[] = str_replace($root.'/', '', $file).' uses '.$m[1].'() — use datetime(), datetimes() or softDeletesDatetime()';
        }
    }
    expect($offending)->toBe([]);
    expect(count($files))->toBeGreaterThan(100);
});

it('makes new migrations with datetimes()', function () {
    expect((string) file_get_contents(dirname(__DIR__, 2).'/stubs/migration.create.stub'))->toContain('$table->datetimes();')->not->toContain('timestamps()');
});
