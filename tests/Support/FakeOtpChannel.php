<?php

declare(strict_types=1);

namespace Tests\Support;

use Wurk\Identity\Contracts\OtpChannel;

/** Captures one-time codes so tests can complete sign-in flows. */
final class FakeOtpChannel implements OtpChannel
{
    /** @var array<string, string> destination => last code */
    public static array $codes = [];

    public static int $sent = 0;

    public static function reset(): void
    {
        self::$codes = [];
        self::$sent = 0;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function send(string $destination, string $code, string $purpose): void
    {
        self::$codes[$destination] = $code;
        self::$sent++;
    }
}
