<?php

declare(strict_types=1);

namespace Wurk\Health\Contracts;

final readonly class CheckResult
{
    private function __construct(
        public bool $ok,
        public ?string $message,
    ) {}

    public static function ok(): self
    {
        return new self(true, null);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message);
    }
}
