<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Tiles on a business's home page (Sprint 13.9a). Modules register a tile; each decides whether this person may see it
 * (permission, package) by returning null when not.
 */
interface DashboardTiles
{
    /** @param  \Closure(): (array{label: string, value: string, hint?: string, href?: string}|null)  $tile */
    public function register(string $key, int $order, \Closure $tile): void;

    /** @return list<array{key: string, label: string, value: string, hint?: string, href?: string}> */
    public function visible(): array;
}
