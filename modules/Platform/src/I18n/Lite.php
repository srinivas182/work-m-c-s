<?php

declare(strict_types=1);

namespace Wurk\Platform\I18n;

use Illuminate\Http\Request;

/**
 * Lite mode (Sprint 24.4, decision F): lighter pages for prepaid data and weak signals. On by itself when the browser
 * says Data Saver is on (the Save-Data header) or the connection is slow (2G/3G, noted by the page in a cookie); a
 * person can also switch it on or off for their phone (Account & security), which then wins.
 */
final class Lite
{
    /** 'on' | 'off' | absent (automatic), per phone. */
    public const COOKIE = 'wurk_lite';

    /** Set by the page when the browser reports a slow connection. */
    public const SLOW_COOKIE = 'wurk_slow';

    public static function on(Request $request): bool
    {
        return match ($request->cookie(self::COOKIE)) {
            'on' => true,
            'off' => false,
            default => strtolower((string) $request->header('Save-Data')) === 'on' || $request->cookie(self::SLOW_COOKIE) === '1',
        };
    }
}
