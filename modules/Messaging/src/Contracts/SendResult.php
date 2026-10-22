<?php

declare(strict_types=1);

namespace Wurk\Messaging\Contracts;

final readonly class SendResult
{
    private function __construct(public bool $ok, public ?string $providerId, public ?string $error) {}

    public static function sent(?string $providerId): self
    {
        return new self(true, $providerId, null);
    }

    public static function failed(string $error): self
    {
        return new self(false, null, mb_substr($error, 0, 500));
    }
}
