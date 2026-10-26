<?php

declare(strict_types=1);

namespace Wurk\Website\Contracts;

/**
 * Published products for shop sections, read when a page is rendered (so product grids are plain HTML).
 * Website binds an empty catalogue; the Shop module provides the real one — Website never depends on Shop.
 */
interface ProductCatalog
{
    /**
     * The current business's published products, in display order.
     *
     * @return list<array{id: string, name: string, slug: string, category: string|null, price_cents: int, varies: bool, compare_at_cents: int|null, photo: string|null, in_stock: bool}>
     */
    public function published(): array;
}
