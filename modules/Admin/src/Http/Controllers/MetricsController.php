<?php

declare(strict_types=1);

namespace Wurk\Admin\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Support\ReportPeriod;

/** Admin → Metrics (Sprint 15.4): WURK's own SaaS metrics, compared with the period before. */
final class MetricsController
{
    public const PERIODS = ['month' => 'This month', '30d' => 'Last 30 days', 'quarter' => 'This quarter', 'year' => 'This year', 'last-month' => 'Last month'];

    public function __invoke(Request $request, PlatformMetrics $metrics): Response
    {
        Gate::authorize('metrics.view');
        $name = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
        $tz = 'Africa/Johannesburg'; // WURK's own calendar
        $period = $name === 'last-month'
            ? new ReportPeriod(CarbonImmutable::now($tz)->subMonthNoOverflow()->startOfMonth()->setTimezone((string) config('app.timezone')), CarbonImmutable::now($tz)->startOfMonth()->setTimezone((string) config('app.timezone')))
            : ReportPeriod::named($name, $tz);
        $sections = $metrics->sections($period);

        return Inertia::render('Admin::Metrics', [
            'period' => $name, 'periods' => self::PERIODS,
            'range' => ['from' => $period->from->setTimezone($tz)->toDateString(), 'to' => $period->to->setTimezone($tz)->toDateString()],
            'highlights' => array_values(array_merge(...array_map(fn ($s) => (array) ($s['highlights'] ?? []), $sections ?: [[]]))),
            'sections' => $sections,
        ]);
    }
}
