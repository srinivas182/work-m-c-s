<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

interface TeamDirectory
{
    /**
     * The current business's team members, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function members(): array;
}
