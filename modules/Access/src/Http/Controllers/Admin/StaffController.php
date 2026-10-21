<?php

declare(strict_types=1);

namespace Wurk\Access\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Actions\PlatformAccess;
use Wurk\Access\Events\AccessChanged;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Models\PlatformRoleAssignment;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Identity\Contracts\PeopleDirectory;

/** WURK staff: invite, change roles, deactivate / reactivate. */
final class StaffController
{
    public function __construct(private readonly PeopleDirectory $people, private readonly Invitations $invitations, private readonly PlatformAccess $access) {}

    public function index(): Response
    {
        Gate::authorize('staff.manage');
        $assignments = PlatformRoleAssignment::query()->get()->groupBy('identity_id');

        return Inertia::render('Access::Admin/Staff', [
            'staff' => array_map(fn (array $p) => $p + [
                'roles' => array_values(array_map('strval', $assignments->get($p['id'])?->pluck('role_key')->all() ?? [])),
            ], $this->people->staff()),
            'pending' => $this->invitations->pendingStaff(),
            'roles' => $this->roleOptions(),
        ]);
    }

    public function invite(Request $request): RedirectResponse
    {
        Gate::authorize('staff.manage');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:80'],
            'role' => ['required', 'string', 'in:'.implode(',', array_column($this->roleOptions(), 'key'))],
        ]);
        $this->invitations->inviteStaff($data['email'], $data['role'], (string) $request->user()?->getAuthIdentifier(), $data['first_name'] ?? null);

        return back()->with('status', "Invitation sent to {$data['email']}.");
    }

    public function revokeInvitation(string $invitation): RedirectResponse
    {
        Gate::authorize('staff.manage');
        $this->invitations->revoke($invitation);

        return back()->with('status', 'Invitation cancelled.');
    }

    public function updateRoles(Request $request, string $identity): RedirectResponse
    {
        Gate::authorize('staff.manage');
        $keys = array_column($this->roleOptions(), 'key');
        $data = $request->validate(['roles' => ['array', 'min:1'], 'roles.*' => ['string', 'in:'.implode(',', $keys)]]);
        $current = array_map('strval', PlatformRoleAssignment::query()->where('identity_id', $identity)->pluck('role_key')->all());

        try {
            foreach (array_diff($data['roles'], $current) as $role) {
                $this->access->grant($identity, $role);
            }
            foreach (array_diff($current, $data['roles']) as $role) {
                $this->access->revoke($identity, $role);
            }
        } catch (AccessRuleViolation $e) {
            throw ValidationException::withMessages(['roles' => $e->getMessage()]);
        }

        return back()->with('status', 'Roles updated.');
    }

    public function deactivate(Request $request, string $identity): RedirectResponse
    {
        Gate::authorize('staff.manage');
        if ((string) $request->user()?->getAuthIdentifier() === $identity) {
            throw ValidationException::withMessages(['staff' => 'You can’t deactivate your own account.']);
        }
        $isLastSuperAdmin = PlatformRoleAssignment::query()->where('role_key', SystemRoles::SUPER_ADMIN)->count() === 1
            && PlatformRoleAssignment::query()->where('identity_id', $identity)->where('role_key', SystemRoles::SUPER_ADMIN)->exists();
        if ($isLastSuperAdmin) {
            throw ValidationException::withMessages(['staff' => 'WURK must always have at least one super admin.']);
        }

        $this->people->deactivateStaff($identity);
        event(new AccessChanged('staff.deactivated', 'identity', $identity, [], platform: true));

        return back()->with('status', 'Staff member deactivated and signed out.');
    }

    public function reactivate(string $identity): RedirectResponse
    {
        Gate::authorize('staff.manage');
        $this->people->reactivateStaff($identity);
        event(new AccessChanged('staff.reactivated', 'identity', $identity, [], platform: true));

        return back()->with('status', 'Staff member reactivated.');
    }

    /** @return list<array{key: string, name: string}> */
    private function roleOptions(): array
    {
        $system = array_map(fn (string $k) => ['key' => $k, 'name' => SystemRoles::DEFINITIONS[$k]['name']], SystemRoles::keys(Permission::PLATFORM));
        $custom = PlatformRole::query()->orderBy('name')->get()->map(fn (PlatformRole $r) => ['key' => $r->key(), 'name' => $r->name])->all();

        return array_values([...$system, ...$custom]);
    }
}
