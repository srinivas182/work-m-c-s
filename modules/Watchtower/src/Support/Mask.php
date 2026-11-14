<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Support;

/**
 * Removes personal data before anything is stored or emailed (POPIA): email addresses, phone numbers, IDs, long numbers,
 * tokens and quoted values (SQL bindings, user input echoed in messages). What's left says *what* went wrong, not *who*.
 */
final class Mask
{
    public static function text(?string $text, int $limit = 2000): string
    {
        if ($text === null || $text === '') {
            return '';
        }
        // The server's own paths (PHP 8.4 names closures "{closure:/srv/app/…:12}"): relative, like everywhere else.
        $text = str_replace(rtrim(base_path(), '/').'/', '', mb_substr($text, 0, $limit * 2));
        $rules = [
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
            '/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i' => '[id]',
            '/\b[0-9A-HJKMNP-TV-Z]{26}\b/i' => '[id]',                      // ULIDs (stored lower-case)
            '/\b[a-z0-9]{32,}\b/i' => '[token]',
            '/\+?\d[\d \-()]{7,}\d/' => '[number]',                         // phone numbers, account numbers, long ids
            "/'(?:[^'\\\\]|\\\\.){1,500}'/" => "'?'",                       // quoted values (SQL bindings, input)
            // Double quotes: PHP names keys and properties this way ("Undefined array key "name"") — keep plain
            // identifiers, mask anything that looks like a value (spaces, digits, long text).
            '/"(?=[^"]*[\s\d])[^"]{1,500}"|"[^"]{41,500}"/' => '"?"',
        ];

        return mb_substr((string) preg_replace(array_keys($rules), array_values($rules), $text), 0, $limit);
    }

    /** For grouping: masked, then every remaining number made the same ("Order 12 not found" ≡ "Order 13 not found"). */
    public static function normalise(?string $text): string
    {
        return (string) preg_replace(['/\b[A-Za-z_\-]*\d[\w\-]*\b/', '/\d+/'], ['0', '0'], self::text($text, 500)); // "A1001", "INV-2002" too
    }

    /** A path relative to the app, so traces don't reveal the server's layout. */
    public static function path(?string $file): string
    {
        if ($file === null || $file === '') {
            return '';
        }
        $base = rtrim(base_path(), '/').'/';

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : basename($file);
    }
}
