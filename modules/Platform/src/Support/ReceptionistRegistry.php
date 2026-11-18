<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Illuminate\Contracts\Container\Container;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Platform\Contracts\ReceptionistTools;

final class ReceptionistRegistry implements ReceptionistTools
{
    /** @var list<class-string<ReceptionistTool>> */
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
        // Built per call: tools depend on the current business, which changes between requests in Octane workers.
        return array_map(fn (string $class): ReceptionistTool => $this->container->make($class), $this->classes);
    }
}
