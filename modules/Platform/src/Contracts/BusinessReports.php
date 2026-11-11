<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use Wurk\Platform\Support\ReportPeriod;

/**
 * A business's reports (Sprint 15.1). Each module registers its own section and computes it from its own data for the
 * period, the previous period and (optionally) one branch; a section returns null when this person may not see it.
 *
 * A section: ['title' => string, 'metrics' => list<{label, value, previous, format: money|count|percent}>,
 *   'chart' => {label, points: list<{label, value}>}|null, 'rows' => {columns: list<string>, data: list<list<string>>}|null,
 *   'highlights' => list<string>]
 */
interface BusinessReports
{
    /** @param \Closure(ReportPeriod): (array<string, mixed>|null) $section */
    public function register(string $key, int $order, \Closure $section): void;

    /** @return list<array<string, mixed>> the sections this person may see, each with its key */
    public function sections(ReportPeriod $period): array;
}
