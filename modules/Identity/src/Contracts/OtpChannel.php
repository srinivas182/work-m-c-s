<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

/**
 * Delivers one-time codes. SMS and WhatsApp implementations arrive with
 * provider settings in Sprint 2; the rest of the platform is unaffected.
 */
interface OtpChannel
{
    public function name(): string;

    public function send(string $destination, string $code, string $purpose): void;
}
