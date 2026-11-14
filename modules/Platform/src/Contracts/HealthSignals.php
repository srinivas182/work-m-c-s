<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Business health (Sprint 16.5): each module says what it sees going wrong for a business — with how much it matters,
 * a plain reason and a suggested next step for WURK's success team. Signals run inside the business's context.
 *
 * A signal returns: list<array{key: string, points: int, reason: string, action: string}> — points are taken off 100.
 */
interface HealthSignals
{
    /** @param \Closure(string): list<array{key: string, points: int, reason: string, action: string}> $signal given the business's id */
    public function register(string $module, \Closure $signal): void;

    /** @return list<array{key: string, points: int, reason: string, action: string}> worst first */
    public function collect(string $tenantId): array;
}
