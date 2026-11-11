<?php

declare(strict_types=1);

namespace Wurk\Admin\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Support\ReportCsv;
use Wurk\Platform\Support\ReportPeriod;

/** Admin → Metrics (Sprint 15.4): WURK's own SaaS metrics, compared with the period before. */
final class MetricsController
{
    public const PERIODS = ['month' => 'This month', '30d' => 'Last 30 days', 'quarter' => 'This quarter', 'year' => 'This year', 'last-month' => 'Last month', 'custom' => 'Choose dates'];

    /** CSV of the same metrics (Sprint 15.4b). */
    public function export(Request $request, PlatformMetrics $metrics): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('metrics.view');
        $period = $this->period($request);
        $name = 'wurk-metrics-'.$period->from->toDateString().'-to-'.$period->to->toDateString().'.csv';

        return response(ReportCsv::csv($metrics->sections($period)), 200, ['Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$name}\"", 'Cache-Control' => 'no-store, private']);
    }

    public function __invoke(Request $request, PlatformMetrics $metrics): Response
    {
        Gate::authorize('metrics.view');
        $name = $this->name($request);
        $tz = self::TZ;
        $period = $this->period($request);
        $sections = $metrics->sections($period);

        return Inertia::render('Admin::Metrics', [
            'period' => $name, 'periods' => self::PERIODS,
            'range' => ['from' => $period->from->setTimezone($tz)->toDateString(), 'to' => $period->to->subSecond()->setTimezone($tz)->toDateString()],
            'highlights' => array_values(array_merge(...array_map(fn ($s) => (array) ($s['highlights'] ?? []), $sections ?: [[]]))),
            'sections' => $sections,
        ]);
    }

    private const TZ = 'Africa/Johannesburg'; // WURK's own calendar

    private function name(Request $request): string
    {
        return array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
    }

    private function period(Request $request): ReportPeriod
    {
        $stored = (string) config('app.timezone');

        if ($this->name($request) === 'custom' && ($custom = ReportPeriod::between((string) $request->query('from'), (string) $request->query('to'), self::TZ)) !== null) {
            return $custom;
        }

        return $this->name($request) === 'last-month'
            ? new ReportPeriod(CarbonImmutable::now(self::TZ)->subMonthNoOverflow()->startOfMonth()->setTimezone($stored), CarbonImmutable::now(self::TZ)->startOfMonth()->setTimezone($stored))
            : ReportPeriod::named($this->name($request) === 'custom' ? 'month' : $this->name($request), self::TZ);
    }
}
