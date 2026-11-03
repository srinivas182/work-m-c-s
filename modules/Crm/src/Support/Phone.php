<?php

declare(strict_types=1);

namespace Wurk\Crm\Support;

/** South African numbers in one form (+27…), so “082 555 0101” and “+27 82 555 0101” are the same person. */
final class Phone
{
    public static function normalise(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+27'.substr($digits, 1);
        }
        if (str_starts_with($digits, '27') && strlen($digits) === 11) {
            return '+'.$digits;
        }

        return str_starts_with(trim((string) $phone), '+') ? '+'.$digits : $digits; // other countries: kept as given
    }
}
