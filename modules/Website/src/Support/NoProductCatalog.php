<?php

declare(strict_types=1);

namespace Wurk\Website\Support;

use Wurk\Website\Contracts\ProductCatalog;

/** Without the Shop module, shop sections have nothing to show (and hide themselves on the public site). */
final class NoProductCatalog implements ProductCatalog
{
    public function published(): array
    {
        return [];
    }

    public function pages(): array
    {
        return [];
    }
}
