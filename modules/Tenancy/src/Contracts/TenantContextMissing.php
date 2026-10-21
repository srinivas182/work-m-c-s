<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

use LogicException;

/**
 * Thrown when tenant data is accessed without a tenant context. This is the
 * isolation guard: it is always a bug, never something to catch and ignore.
 */
final class TenantContextMissing extends LogicException
{
    public static function forModel(string $model): self
    {
        return new self("Tenant model [{$model}] was used without a tenant context.");
    }

    public static function make(): self
    {
        return new self('No tenant context is active.');
    }

    public static function mismatch(string $model, string $expected, string $actual): self
    {
        return new self("Tenant model [{$model}] belongs to tenant {$actual}, but the active tenant is {$expected}.");
    }
}
