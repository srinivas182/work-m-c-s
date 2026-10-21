<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Support;

/**
 * Business handles appear in URLs (app.wurk.co.za/{handle}, {handle}.wurk.co.za):
 * 3–40 characters, lowercase letters, digits and single hyphens, not reserved.
 */
final class HandleRules
{
    public const PATTERN = '[a-z0-9](?:[a-z0-9]|-(?=[a-z0-9])){2,39}';

    /**
     * Route constraint for {tenant}: the handle pattern, refusing reserved words.
     *
     * Laravel matches routes with an explicit domain before routes without one, so
     * on the back-office host "/{tenant}" would otherwise capture platform paths
     * such as /sign-in. Every static first path segment on that host must be in
     * wurk.tenancy.reserved_handles (enforced by an architecture test).
     */
    public static function routePattern(): string
    {
        /** @var list<string> $reserved */
        $reserved = config('wurk.tenancy.reserved_handles', []);
        $words = implode('|', array_map(fn (string $w) => preg_quote($w, '#'), $reserved));

        return $words === '' ? self::PATTERN : '(?!(?:'.$words.')(?![a-z0-9-]))'.self::PATTERN;
    }

    /** Returns null when valid, otherwise a plain-language reason. */
    public static function problem(string $handle): ?string
    {
        if (preg_match('/^'.self::PATTERN.'$/', $handle) !== 1) {
            return 'Use 3–40 lowercase letters, numbers and single hyphens, starting and ending with a letter or number.';
        }

        /** @var list<string> $reserved */
        $reserved = config('wurk.tenancy.reserved_handles', []);

        if (in_array($handle, $reserved, true)) {
            return 'That name is reserved. Please choose another.';
        }

        return null;
    }
}
