<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Support\AgentCommission;

/** Admin → Agents → Commission: the percentages per level, set by WURK's super admin (decision A). */
final class CommissionController
{
    public function __construct(private AgentCommission $commission) {}

    public function show(): Response
    {
        Gate::authorize('agents.view');

        return Inertia::render('Agents::Admin/Commission', ['levelBp' => $this->commission->percentages(), 'maxLevels' => AgentCommission::MAX_LEVELS,
            'refundDays' => $this->commission->refundDays(), 'canManage' => Gate::allows('agents.manage')]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('agents.manage');
        $d = $request->validate(['level_bp' => ['required', 'array', 'min:1', 'max:'.(AgentCommission::MAX_LEVELS + 1)], 'level_bp.*' => ['required', 'integer', 'min:0', 'max:10000']],
            ['level_bp.max' => 'Up to '.AgentCommission::MAX_LEVELS.' levels above the business’s own agent.']);
        $this->commission->setPercentages(array_values(array_map('intval', $d['level_bp'])));

        return back()->with('status', 'Commission percentages saved. They apply to payments from now on.');
    }
}
