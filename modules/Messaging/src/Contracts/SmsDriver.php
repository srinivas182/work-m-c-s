<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

/**
 * One SMS provider. Implementations never throw for provider errors; they
 * return a failed SendResult so callers can fall back or record the failure.
 */
interface SmsDriver
{
    /** @return array<string, array{label: string, secret: bool}> credential fields shown in the admin */
    public static function fields(): array;

    /**
     * @param  array<string, string>  $credentials
     * @param  array<string, string>  $settings
     */
    public function send(string $to, string $text, array $credentials, array $settings): SendResult;
}
