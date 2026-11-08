<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentAttribution;
use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Admin → Business → Agent: who brought this business, and correcting it (Sprint 14.2). */
final class BusinessAgentController
{
    public function __construct(private AgentAttribution $attribution, private TenantDirectory $tenants) {}

    public function show(string $tenant, PeopleDirectory $people): Response
    {
        Gate::authorize('agents.view');
        $t = $this->tenants->find($tenant) ?? abort(404);
        $agents = Agent::query()->orderBy('created_at')->get();
        $names = $people->names(array_values(array_map('strval', $agents->pluck('identity_id')->all())));
        $current = $this->attribution->of($tenant);

        return Inertia::render('Agents::Admin/BusinessAgent', [
            'business' => ['id' => $tenant, 'name' => $t->displayName()],
            'current' => $current === null ? null : ['agent_id' => $current->agent_id, 'source' => $current->source, 'at' => substr((string) $current->attributed_at, 0, 10)],
            'agents' => $agents->map(fn (Agent $a) => ['id' => $a->id, 'name' => ($names[$a->identity_id] ?? 'Agent').' ('.$a->code.')'])->all(),
            'canManage' => Gate::allows('agents.manage'),
        ]);
    }

    public function update(Request $request, string $tenant): RedirectResponse
    {
        Gate::authorize('agents.manage');
        $this->tenants->find($tenant) ?? abort(404);
        $agentId = $request->validate(['agent_id' => ['nullable', Rule::exists('agents', 'id')]])['agent_id'] ?? null;
        $this->attribution->set($tenant, $agentId);

        return back()->with('status', $agentId === null ? 'This business no longer has an agent.' : 'Agent saved.');
    }
}
