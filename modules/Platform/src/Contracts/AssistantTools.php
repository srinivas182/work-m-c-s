<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** The tools modules offer to "Ask WURK" (Sprint 16.2). Registered by class name and built when first needed. */
interface AssistantTools
{
    /** @param class-string<AssistantTool> $class */
    public function register(string $class): void;

    /** @return list<AssistantTool> */
    public function all(): array;

    public function find(string $name): ?AssistantTool;
}
