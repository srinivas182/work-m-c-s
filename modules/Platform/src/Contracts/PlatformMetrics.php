<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use Wurk\Platform\Support\ReportPeriod;

/**
 * WURK's own SaaS metrics for the super admin (Sprint 15.4). Like a business's reports: each module registers its section
 * (same shape as BusinessReports) and computes it for the period and the one before; null when this person may not see it.
 */
interface PlatformMetrics
{
    /** @param \Closure(ReportPeriod): (array<string, mixed>|null) $section */
    public function register(string $key, int $order, \Closure $section): void;

    /** @return list<array<string, mixed>> */
    public function sections(ReportPeriod $period): array;
}
