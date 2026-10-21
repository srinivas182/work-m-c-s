<?php

declare(strict_types=1);

namespace Wurk\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects passwords found in a list of ~98 000 commonly used passwords
 * (NCSC "100k most used", via SecLists, MIT licence), per NIST SP 800-63B.
 */
final class NotCommonPassword implements ValidationRule
{
    /** @var array<string, true>|null */
    private static ?array $list = null;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && isset(self::list()[strtolower($value)])) {
            $fail('This password is too common. Choose something harder to guess — a short phrase works well.');
        }
    }

    /** @return array<string, true> */
    private static function list(): array
    {
        if (self::$list === null) {
            $path = dirname(__DIR__, 2).'/resources/passwords/common-passwords.txt.gz';
            $lines = gzfile($path) ?: [];
            self::$list = array_fill_keys(array_map('trim', $lines), true);
        }

        return self::$list;
    }
}
