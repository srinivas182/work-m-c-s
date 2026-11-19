<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\TeamHours;

final class TeamHoursRegistry implements TeamHours
{
    /** @var array<string, \Closure> */
    private array $sources = [];

    public function register(string $module, \Closure $source): void
    {
        $this->sources[$module] = $source;
    }

    public function weekly(): array
    {
        $out = [];
        foreach ($this->sources as $source) {
            foreach ($source() as $h) {
                $out[] = $h;
            }
        }

        return $out;
    }
}
