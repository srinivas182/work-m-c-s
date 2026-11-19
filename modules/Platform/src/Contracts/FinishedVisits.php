<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Visits and orders that have just finished for the current business (Sprint 25.3), gathered from each module:
 * appointments whose time has passed (Bookings) and orders delivered or collected (Shop). Reviews asks these
 * customers how it was.
 */
interface FinishedVisits
{
    /**
     * A source returns items finished between two moments, with: key (stable: one request per visit), kind, title
     * (what it was, e.g. "Knotless braids"), customer, phone|null, email|null, finished_at (ISO 8601).
     *
     * @param  \Closure(\DateTimeInterface, \DateTimeInterface): iterable<array<string, mixed>>  $source
     */
    public function register(string $module, \Closure $source): void;

    /**
     * @return list<array{key: string, kind: string, title: string, customer: string, phone: string|null, email: string|null, finished_at: string, module: string}>
     */
    public function between(\DateTimeInterface $from, \DateTimeInterface $until): array;
}
