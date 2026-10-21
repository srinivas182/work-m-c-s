<?php

declare(strict_types=1);

namespace Wurk\Identity\Support;

use Illuminate\Validation\ValidationException;

/**
 * An email address or mobile number, normalised (lower-case email; E.164 phone).
 * South African local numbers (082 123 4567) are accepted and converted.
 */
final readonly class Destination
{
    private function __construct(public string $type, public string $value) {}

    public static function parse(string $input, string $field = 'identifier'): self
    {
        $input = trim($input);

        if (str_contains($input, '@')) {
            $email = strtolower($input);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages([$field => 'Enter a valid email address.']);
            }

            return new self('email', $email);
        }

        $digits = preg_replace('/[\s\-().]/', '', $input) ?? '';
        $country = (string) config('wurk.identity.default_country_code', '27');

        $e164 = match (true) {
            preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 => $digits,
            preg_match('/^00[1-9]\d{7,14}$/', $digits) === 1 => '+'.substr($digits, 2),
            preg_match('/^0\d{9}$/', $digits) === 1 => '+'.$country.substr($digits, 1),
            preg_match('/^'.$country.'\d{9}$/', $digits) === 1 => '+'.$digits,
            default => null,
        };

        if ($e164 === null) {
            throw ValidationException::withMessages([$field => 'Enter a valid email address or mobile number.']);
        }

        return new self('phone', $e164);
    }

    public function column(): string
    {
        return $this->type;
    }

    /** Stable, non-reversible key used for rate limits and challenge lookup. */
    public function hash(): string
    {
        return hash_hmac('sha256', $this->type.':'.$this->value, (string) config('app.key'));
    }

    /** For display: t***@example.com, +27 ••• ••• 4567 */
    public function masked(): string
    {
        if ($this->type === 'email') {
            [$local, $domain] = explode('@', $this->value, 2);

            return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
        }

        return substr($this->value, 0, 3).' ••• ••• '.substr($this->value, -4);
    }
}
