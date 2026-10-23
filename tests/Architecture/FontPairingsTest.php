<?php

declare(strict_types=1);

/*
| The server offers font pairings (config/website.php); the site theme must
| implement every one, or an owner's choice silently falls back to "modern".
*/

it('implements every font pairing the server offers', function () {
    $root = dirname(__DIR__, 2);
    $offered = array_keys((require $root.'/modules/Website/config/website.php')['font_pairings']);
    preg_match('/export const FONT_PAIRINGS[^{]*\{(.*?)\n\};/s', (string) file_get_contents($root.'/modules/Website/resources/js/site/theme.ts'), $m);
    preg_match_all('/^\s+([a-z]+):/m', $m[1] ?? '', $keys);

    expect(array_values(array_diff($offered, $keys[1])))->toBe([])
        ->and(count($offered))->toBe(8);
});
