<?php

declare(strict_types=1);

namespace Wurk\Platform\Support;

use Throwable;
use Wurk\Platform\Contracts\HealthSignals;

final class HealthRegistry implements HealthSignals
{
    /** @var array<string, \Closure> */
    private array $signals = [];

    public function register(string $module, \Closure $signal): void
    {
        $this->signals[$module] = $signal;
    }

    public function collect(string $tenantId): array
    {
        $found = [];
        foreach ($this->signals as $module => $signal) {
            try {
                /** @var list<array{key: string, points: int, reason: string, action: string}> $list */
                $list = $signal($tenantId);
                array_push($found, ...$list);
            } catch (Throwable $e) {
                report($e); // one module's problem never hides the others' signals
            }
        }
        usort($found, fn (array $a, array $b) => $b['points'] <=> $a['points']);

        return $found;
    }
}
