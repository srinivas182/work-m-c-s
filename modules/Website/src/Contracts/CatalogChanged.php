<?php

declare(strict_types=1);

namespace Wurk\Website\Contracts;

/** A business's products changed (raised by Shop): published pages with shop sections are re-rendered. */
final readonly class CatalogChanged
{
    public function __construct(public string $tenantId) {}
}
