<?php

declare(strict_types=1);

namespace Wurk\Reports\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Reports\Support\ReportExport;
use Wurk\Reports\Support\ReportSummaries;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Overview (Sprint 15.1): every module's section for a period, compared with the previous one. */
final class ReportsController
{
    public const PERIODS = ['month' => 'This month', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'quarter' => 'This quarter', 'year' => 'This year'];

    /** @return array{0: ReportPeriod, 1: list<array<string, mixed>>} the period and sections this person may see */
    private function report(Request $request, TenantContext $context, BranchDirectory $branches, TeamDirectory $team, BusinessReports $reports): array
    {
        $me = collect($team->roster())->firstWhere('identity_id', (string) Auth::id());
        $mine = $me === null || $me['all_branches'] ? null : $me['branches'];
        $visible = array_values(array_filter($branches->all(), fn ($b) => $mine === null || in_array($b['id'], $mine, true)));
        $branch = (string) $request->query('branch', '');
        $chosen = $branch !== '' && in_array($branch, array_column($visible, 'id'), true) ? [$branch] : $mine;
        $name = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
        $period = ReportPeriod::named($name, $context->current()->timezone(), $chosen);

        return [$period, $reports->sections($period)];
    }

    /** CSV or PDF of the same report (Sprint 15.1b). */
    public function export(Request $request, string $format, TenantContext $context, BranchDirectory $branches, TeamDirectory $team, BusinessReports $reports, ReportExport $export): \Symfony\Component\HttpFoundation\Response
    {
        Gate::authorize('reports.view');
        [$period, $sections] = $this->report($request, $context, $branches, $team, $reports);
        $name = $context->current()->handle().'-report-'.$period->from->toDateString().'-to-'.$period->to->toDateString();
        if ($format === 'pdf') {
            $highlights = array_values(array_merge(...array_map(fn ($s) => (array) ($s['highlights'] ?? []), $sections ?: [[]])));

            return response($export->pdf($sections, $highlights, $context->current()->displayName(), $period), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"{$name}.pdf\""]);
        }

        return response($export->csv($sections), 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
    }

    public function summary(Request $request, ReportSummaries $summaries): RedirectResponse
    {
        Gate::authorize('billing.manage');
        $summaries->save((string) $request->validate(['summary' => ['required', 'in:weekly,monthly,off']])['summary']);

        return back()->with('status', 'Saved.');
    }

    public function __invoke(Request $request, TenantContext $context, BranchDirectory $branches, TeamDirectory $team, BusinessReports $reports): Response
    {
        Gate::authorize('reports.view');
        $me = collect($team->roster())->firstWhere('identity_id', (string) Auth::id());
        $mine = $me === null || $me['all_branches'] ? null : $me['branches']; // managers: their own branches only
        $visible = array_values(array_filter($branches->all(), fn ($b) => $mine === null || in_array($b['id'], $mine, true)));
        $branch = (string) $request->query('branch', '');
        $chosen = $branch !== '' && in_array($branch, array_column($visible, 'id'), true) ? [$branch] : $mine;
        $name = array_key_exists((string) $request->query('period'), self::PERIODS) ? (string) $request->query('period') : 'month';
        $period = ReportPeriod::named($name, $context->current()->timezone(), $chosen);
        $sections = $reports->sections($period);

        return Inertia::render('Reports::Overview', [
            'period' => $name, 'periods' => self::PERIODS, 'branch' => $chosen !== null && count($chosen) === 1 && $branch !== '' ? $branch : '',
            'branches' => $branches->several() ? array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['name']], $visible) : [],
            'allBranches' => $mine === null,
            'range' => ['from' => $period->from->toDateString(), 'to' => $period->to->toDateString()],
            'highlights' => array_values(array_merge(...array_map(fn ($s) => (array) ($s['highlights'] ?? []), $sections ?: [[]]))),
            'sections' => $sections,
            'summary' => Gate::allows('billing.manage') ? app(ReportSummaries::class)->setting() : null,
        ]);
    }
}
