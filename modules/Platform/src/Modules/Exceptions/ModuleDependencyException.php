<?php

declare(strict_types=1);

namespace Wurk\Platform\Modules\Exceptions;

use RuntimeException;

final class ModuleDependencyException extends RuntimeException
{
    public static function duplicateKey(string $key): self
    {
        return new self("Two modules declare the same key \"{$key}\".");
    }

    public static function missing(string $module, string $dependency): self
    {
        return new self("Module \"{$module}\" depends on \"{$dependency}\", which is not installed.");
    }

    /**
     * @param  array<int, string>  $chain
     */
    public static function circular(array $chain): self
    {
        return new self('Circular module dependency: '.implode(' -> ', $chain));
    }
}
