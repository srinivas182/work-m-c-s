<?php

declare(strict_types=1);

namespace Wurk\Reports\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Reports → Overview (Sprint 15.1): every module's section for a period, compared with the previous one. */
final class ReportsController
{
    public const PERIODS = ['month' => 'This month', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'quarter' => 'This quarter', 'year' => 'This year'];

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
        ]);
    }
}
