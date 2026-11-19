<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Throwable;
use Wurk\Platform\Contracts\NeedsYouNow;

final class NeedsYouRegistry implements NeedsYouNow
{
    /** @var array<string, \Closure> */
    private array $sources = [];

    public function register(string $key, \Closure $source): void
    {
        $this->sources[$key] = $source;
    }

    public function all(int $limit = 30): array
    {
        $items = [];
        foreach ($this->sources as $key => $source) {
            try {
                foreach ($source() as $item) {
                    $items[] = ['source' => (string) $key, 'url' => null, 'actions' => [], ...$item];
                }
            } catch (Throwable $e) {
                report($e); // one module's trouble never hides the rest of the list
            }
        }
        usort($items, fn (array $a, array $b) => strcmp((string) $b['at'], (string) $a['at']));

        return array_slice($items, 0, $limit);
    }
}
