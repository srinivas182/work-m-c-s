<?php

declare(strict_types=1);

namespace Wurk\Platform\Modules\Exceptions;

use RuntimeException;

final class InvalidModuleManifest extends RuntimeException
{
    public static function missing(string $file): self
    {
        return new self("Module manifest not found: {$file}");
    }

    public static function malformed(string $file, string $reason): self
    {
        return new self("Module manifest {$file} is not valid JSON: {$reason}");
    }

    public static function invalid(string $file, string $reason): self
    {
        return new self("Module manifest {$file} is invalid: {$reason}");
    }
}
