<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * What each team member brought in, from each module (Sprint 26.4: commission). Bookings gives services done; Sales
 * gives what each person sold at the till. Sources run inside the business.
 */
interface TeamTakings
{
    /**
     * @param  \Closure(\DateTimeInterface $from, \DateTimeInterface $until): iterable<array{member_id: string|null, identity_id: string|null, kind: string, cents: int}>  $source
     *                                                                                                                                                                              kind: services | products
     */
    public function register(string $module, \Closure $source): void;

    /** @return list<array{member_id: string|null, identity_id: string|null, kind: string, cents: int, module: string}> */
    public function between(\DateTimeInterface $from, \DateTimeInterface $until): array;
}
