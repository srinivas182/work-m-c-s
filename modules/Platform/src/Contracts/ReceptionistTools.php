<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** The tools modules offer to the AI receptionist (19.2). Registered by class name and built when first needed. */
interface ReceptionistTools
{
    /** @param class-string<ReceptionistTool> $class */
    public function register(string $class): void;

    /** @return list<ReceptionistTool> */
    public function all(): array;
}
