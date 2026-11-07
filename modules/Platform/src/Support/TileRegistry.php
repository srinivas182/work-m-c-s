<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\DashboardTiles;

final class TileRegistry implements DashboardTiles
{
    /** @var array<string, array{order: int, tile: \Closure}> */
    private array $tiles = [];

    public function register(string $key, int $order, \Closure $tile): void
    {
        $this->tiles[$key] = ['order' => $order, 'tile' => $tile];
    }

    public function visible(): array
    {
        $tiles = $this->tiles;
        uasort($tiles, fn ($a, $b) => $a['order'] <=> $b['order']);
        $out = [];
        foreach ($tiles as $key => $t) {
            /** @var array{label: string, value: string, hint?: string, href?: string}|null $tile */
            $tile = ($t['tile'])();
            if ($tile !== null) {
                $out[] = ['key' => (string) $key, ...$tile];
            }
        }

        return $out;
    }
}
