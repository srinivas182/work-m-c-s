<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Wurk\Platform\Contracts\TeamTakings;

final class TeamTakingsRegistry implements TeamTakings
{
    /** @var array<string, \Closure> */
    private array $sources = [];

    public function register(string $module, \Closure $source): void
    {
        $this->sources[$module] = $source;
    }

    public function between(\DateTimeInterface $from, \DateTimeInterface $until): array
    {
        $out = [];
        foreach ($this->sources as $module => $source) {
            foreach ($source($from, $until) as $t) {
                $out[] = ['member_id' => $t['member_id'] ?? null, 'identity_id' => $t['identity_id'] ?? null, 'kind' => (string) $t['kind'], 'cents' => (int) $t['cents'], 'module' => (string) $module];
            }
        }

        return $out;
    }
}
