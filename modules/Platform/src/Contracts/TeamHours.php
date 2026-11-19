<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/** Each team member's usual weekly hours (Sprint 26.4: the roster starts from booking hours, so nothing is typed twice). */
interface TeamHours
{
    /** @param \Closure(): iterable<array{member_id: string, weekday: int, starts: string, ends: string, branch_id: string|null}> $source weekday 0 = Monday */
    public function register(string $module, \Closure $source): void;

    /** @return list<array{member_id: string, weekday: int, starts: string, ends: string, branch_id: string|null}> */
    public function weekly(): array;
}
