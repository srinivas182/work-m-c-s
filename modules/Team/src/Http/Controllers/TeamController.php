<?php

declare(strict_types=1);

namespace Wurk\Team\Http\Controllers;

use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Settings → Team — who's on the team, their role and branches; invite, change, remove. */
final class TeamController
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private Invitations $invitations, private BranchDirectory $branches, private AuditLog $audit) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Team::Team', [
            'members' => array_values(array_filter($this->team->roster(), fn (array $m) => $m['active'])),
            'roles' => $this->team->roles(),
            'branches' => array_map(fn (array $b) => ['id' => $b['id'], 'name' => $b['name']], $this->branches->open()),
            'pending' => $this->invitations->pendingMembers($this->context->id()),
            'me' => (string) $request->user()?->getAuthIdentifier(),
            'canManage' => Gate::allows('team.manage'),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        Gate::authorize('team.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:160'], 'role' => ['required', 'in:'.implode(',', array_column($this->team->roles(), 'key'))],
            'first_name' => ['nullable', 'string', 'max:60'], 'last_name' => ['nullable', 'string', 'max:60'],
        ]);
        $seats = count(array_filter($this->team->roster(), fn (array $m) => $m['active'])) + count($this->invitations->pendingMembers($this->context->id()));
        if (($refusal = app(SeatPolicy::class)->refusal($this->context->id(), $seats)) !== null) {
            throw ValidationException::withMessages(['email' => $refusal]);
        }
        $this->invitations->inviteMember($this->context->id(), $this->context->current()->displayName(), strtolower($data['email']), $data['role'],
            (string) $request->user()?->getAuthIdentifier(), $data['first_name'] ?? null, $data['last_name'] ?? null);
        $this->audit->record('team.invited', 'invitation', $data['email'], ['role' => $data['role']]);

        return back()->with('status', 'Invitation sent to '.$data['email'].'.');
    }

    public function role(Request $request, string $member): RedirectResponse
    {
        $m = $this->manageable($request, $member);
        $role = (string) $request->validate(['role' => ['required', 'in:'.implode(',', array_column($this->team->roles(), 'key'))]])['role'];
        $this->attempt(fn () => $this->team->setRole($member, $role));
        $restricted = collect($this->team->roles())->firstWhere('key', $role)['branch_restricted'] ?? false;
        if ($restricted && $m['all_branches']) { // a branch manager starts with the main branch until the owner chooses
            $this->team->setBranches($member, [$this->branches->main()]);
        }

        return back()->with('status', 'Role changed.');
    }

    public function branches(Request $request, string $member): RedirectResponse
    {
        $m = $this->manageable($request, $member);
        $data = $request->validate(['all' => ['required', 'boolean'], 'branches' => ['array'], 'branches.*' => ['string', 'in:'.implode(',', array_column($this->branches->open(), 'id'))]]);
        $restricted = collect($this->team->roles())->firstWhere('key', $m['role'])['branch_restricted'] ?? false;
        if ($data['all'] && $restricted) {
            throw ValidationException::withMessages(['branches' => 'A branch manager looks after particular branches — choose them.']);
        }
        if (! $data['all'] && empty($data['branches'])) {
            throw ValidationException::withMessages(['branches' => 'Choose at least one branch.']);
        }
        $this->team->setBranches($member, $data['all'] ? null : array_values($data['branches']));
        $this->audit->record('team.branches', 'member', $member, []);

        return back()->with('status', 'Branches saved.');
    }

    public function remove(Request $request, string $member): RedirectResponse
    {
        $this->manageable($request, $member);
        $this->attempt(fn () => $this->team->deactivate($member));

        return back()->with('status', 'Removed from the team. Their past work stays on record.');
    }

    public function revokeInvite(string $invitation): RedirectResponse
    {
        Gate::authorize('team.manage');
        abort_unless(in_array($invitation, array_column($this->invitations->pendingMembers($this->context->id()), 'id'), true), 404);
        $this->invitations->revoke($invitation);

        return back()->with('status', 'Invitation cancelled.');
    }

    /** @return array{id: string, identity_id: string, name: string, role: string, all_branches: bool, branches: list<string>, active: bool} */
    private function manageable(Request $request, string $member): array
    {
        Gate::authorize('team.manage');
        $m = collect($this->team->roster())->firstWhere('id', $member) ?? abort(404);
        if ($m['identity_id'] === (string) $request->user()?->getAuthIdentifier()) {
            throw ValidationException::withMessages(['member' => 'You can’t change your own role or remove yourself — ask another owner.']);
        }

        return $m;
    }

    private function attempt(callable $action): void
    {
        try {
            $action();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['member' => $e->getMessage()]);
        }
    }
}
