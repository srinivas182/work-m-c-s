<?php

declare(strict_types=1);

namespace Wurk\Team\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\BranchRestriction;
use Wurk\Team\Support\Settlements;
use Wurk\Tenancy\Contracts\BranchDirectory;

/** Settings → Branches → Between branches: what one branch owes another, month by month (settled by hand). */
final class SettlementsController
{
    public function __construct(private Settlements $settlements, private BranchDirectory $branches) {}

    public function index(Request $request): Response
    {
        $month = $this->month($request);
        $names = array_column($this->branches->all(), 'name', 'id');
        $limit = app(BranchRestriction::class)->branchRestriction((string) $request->user()?->getAuthIdentifier());
        $pairs = array_values(array_filter($this->settlements->statement($month),
            fn (array $p) => $limit === null || in_array($p['from'], $limit, true) || in_array($p['to'], $limit, true))); // branch managers: their branches only

        return Inertia::render('Team::Settlements', [
            'month' => $month,
            'label' => date('F Y', (int) strtotime($month.'-01')),
            'previous' => date('Y-m', (int) strtotime($month.'-01 -1 month')),
            'next' => date('Y-m', (int) strtotime($month.'-01 +1 month')),
            'pairs' => array_map(fn (array $p) => [...$p, 'from_name' => $names[$p['from']] ?? 'A closed branch', 'to_name' => $names[$p['to']] ?? 'A closed branch'], $pairs),
            'canSettle' => Gate::allows('branches.manage'),
        ]);
    }

    public function settle(Request $request): RedirectResponse
    {
        Gate::authorize('branches.manage');
        $data = $request->validate(['month' => ['required', 'date_format:Y-m'], 'from' => ['required', 'string'], 'to' => ['required', 'string']]);
        $by = trim((string) data_get($request->user(), 'first_name').' '.(string) data_get($request->user(), 'last_name')) ?: 'Owner';
        $n = $this->settlements->settle($data['month'], $data['from'], $data['to'], $by);

        return back()->with('status', $n > 0 ? 'Marked as settled.' : 'Nothing left to settle.');
    }

    private function month(Request $request): string
    {
        $m = (string) $request->query('month', '');

        return preg_match('/^\d{4}-\d{2}$/', $m) === 1 ? $m : now()->format('Y-m');
    }
}
