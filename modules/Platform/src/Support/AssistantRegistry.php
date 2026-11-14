<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Illuminate\Contracts\Container\Container;
use Wurk\Platform\Contracts\AssistantTool;
use Wurk\Platform\Contracts\AssistantTools;

final class AssistantRegistry implements AssistantTools
{
    /** @var list<class-string<AssistantTool>> */
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
        return array_map(fn (string $class): AssistantTool => $this->container->make($class), $this->classes);
    }

    public function find(string $name): ?AssistantTool
    {
        foreach ($this->all() as $tool) {
            if ($tool->name() === $name) {
                return $tool;
            }
        }

        return null;
    }
}
