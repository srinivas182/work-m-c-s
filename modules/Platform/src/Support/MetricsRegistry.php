<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\PlatformMetrics;

final class MetricsRegistry implements PlatformMetrics
{
    /** @var array<string, array{order: int, section: \Closure}> */
    private array $sections = [];

    public function register(string $key, int $order, \Closure $section): void
    {
        $this->sections[$key] = ['order' => $order, 'section' => $section];
    }

    public function sections(ReportPeriod $period): array
    {
        $all = $this->sections;
        uasort($all, fn ($a, $b) => $a['order'] <=> $b['order']);
        $out = [];
        foreach ($all as $key => $s) {
            /** @var array<string, mixed>|null $section */
            $section = ($s['section'])($period);
            if ($section !== null) {
                $out[] = ['key' => (string) $key, ...$section];
            }
        }

        return $out;
    }
}
