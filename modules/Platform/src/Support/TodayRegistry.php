<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\TodayList;

final class TodayRegistry implements TodayList
{
    /** @var array<string, array{order: int, section: \Closure}> */
    private array $sections = [];

    public function register(string $key, int $order, \Closure $section): void
    {
        $this->sections[$key] = ['order' => $order, 'section' => $section];
    }

    public function for(string $identityId): array
    {
        $sections = $this->sections;
        uasort($sections, fn ($a, $b) => $a['order'] <=> $b['order']);
        $out = [];
        foreach ($sections as $key => $s) {
            $section = ($s['section'])($identityId);
            if (is_array($section)) {
                $out[] = ['key' => (string) $key, 'empty' => null, 'link' => null, ...$section];
            }
        }

        return $out;
    }
}
