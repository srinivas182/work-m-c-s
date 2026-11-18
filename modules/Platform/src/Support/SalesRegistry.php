<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Illuminate\Contracts\Container\Container;
use Wurk\Platform\Contracts\SalesTool;
use Wurk\Platform\Contracts\SalesTools;

final class SalesRegistry implements SalesTools
{
    /** @var list<class-string<SalesTool>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    public function register(string $class): void
    {
        if (! in_array($class, $this->classes, true)) {
            $this->classes[] = $class;
        }
    }

    public function all(): array
    {
        // Built per call: a tool may read the current request (Octane workers serve many).
        return array_map(fn (string $class): SalesTool => $this->container->make($class), $this->classes);
    }
}
