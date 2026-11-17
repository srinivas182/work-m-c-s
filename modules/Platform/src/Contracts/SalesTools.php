<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** The tools modules offer to WURK's sales assistant (19.5). Registered by class name and built when first needed. */
interface SalesTools
{
    /** @param class-string<SalesTool> $class */
    public function register(string $class): void;

    /** @return list<SalesTool> */
    public function all(): array;
}
