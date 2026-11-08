<?php

declare(strict_types=1);

namespace Wurk\Agents\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Agents\Models\Agent;
use Wurk\Agents\Support\AgentRoster;
use Wurk\Identity\Contracts\PeopleDirectory;

/** Admin → Agents: the sponsor hierarchy (Sprint 14.1). */
final class AgentsController
{
    public function __construct(private AgentRoster $roster, private PeopleDirectory $people) {}

    public function index(): Response
    {
        Gate::authorize('agents.view');
        $agents = Agent::query()->orderBy('created_at')->get();
        $names = $this->people->names(array_values(array_map('strval', $agents->pluck('identity_id')->all())));
        $byId = $agents->keyBy('id');
        $team = $agents->countBy('sponsor_id');

        return Inertia::render('Agents::Admin/Agents', [
            'canManage' => Gate::allows('agents.manage'),
            'agents' => $agents->map(fn (Agent $a) => [
                'id' => $a->id, 'name' => $names[$a->identity_id] ?? 'Agent', 'code' => $a->code, 'status' => $a->status, 'months' => $a->commission_months,
                'sponsor_id' => $a->sponsor_id, 'sponsor' => $a->sponsor_id === null ? null : ($names[$byId[$a->sponsor_id]->identity_id ?? ''] ?? 'Agent'),
                'direct' => (int) ($team[$a->id] ?? 0), 'team' => count($this->roster->downline($a->id)),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('agents.manage');
        $d = $request->validate([
            'first_name' => ['required', 'string', 'max:60'], 'last_name' => ['required', 'string', 'max:60'],
            'contact' => ['required', 'string', 'max:120'], 'sponsor_id' => ['nullable', 'string', 'max:26'],
            'months' => ['nullable', 'integer', 'min:1', 'max:600'],
        ], ['contact.required' => 'Their phone number or email — they sign in with it.', 'months.min' => 'Leave empty for lifetime.']);

        return $this->act(fn () => $this->roster->create($d['contact'], $d['first_name'], $d['last_name'], $d['sponsor_id'] ?? null, isset($d['months']) ? (int) $d['months'] : null),
            'Agent added.');
    }

    public function update(Request $request, string $agent): RedirectResponse
    {
        Gate::authorize('agents.manage');
        $a = Agent::query()->findOrFail($agent);
        $d = $request->validate(['status' => ['required', Rule::in(['active', 'suspended'])], 'months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'sponsor_id' => ['nullable', 'string', 'max:26']]);

        return $this->act(function () use ($a, $d) {
            if (($d['sponsor_id'] ?? null) !== $a->sponsor_id) {
                $this->roster->move($a, $d['sponsor_id'] ?? null);
            }
            $this->roster->update($a->refresh(), $d['status'], isset($d['months']) ? (int) $d['months'] : null);
        }, 'Saved.');
    }

    private function act(\Closure $fn, string $done): RedirectResponse
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['agent' => $e->getMessage()]);
        }

        return back()->with('status', $done);
    }
}
