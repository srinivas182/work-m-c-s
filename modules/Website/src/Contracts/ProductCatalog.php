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

    /**
     * Full details of each published product, for its own page.
     *
     * @return list<array{id: string, name: string, slug: string, description: string|null, photos: list<string>, options: list<string>, price_cents: int, varies: bool, compare_at_cents: int|null, in_stock: bool, variants: list<array{id: string, label: string|null, price_cents: int, compare_at_cents: int|null, in_stock: bool}>}>
     */
    public function pages(): array;

    /** Whether customers can order online now (product pages show "Add to cart"; the cart page exists). */
    public function canOrder(): bool;
}
