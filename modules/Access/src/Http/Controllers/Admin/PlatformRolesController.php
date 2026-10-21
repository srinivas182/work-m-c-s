<?php

declare(strict_types=1);

namespace Wurk\Access\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Actions\CustomRoles;
use Wurk\Access\Exceptions\AccessRuleViolation;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Permissions\Permission;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Roles\SystemRoles;

/** WURK staff roles: built-in roles (read-only) and custom roles from declared permissions. */
final class PlatformRolesController
{
    public function __construct(private readonly CustomRoles $roles, private readonly PermissionRegistry $registry) {}

    public function index(): Response
    {
        Gate::authorize('platform-roles.manage');
        $permissions = array_values(array_filter($this->registry->all(), fn (Permission $p) => $p->scope === Permission::PLATFORM));

        return Inertia::render('Access::Admin/Roles', [
            'permissions' => array_map(fn (Permission $p) => ['key' => $p->key, 'label' => $p->label], $permissions),
            'system' => array_map(fn (string $k) => [
                'key' => $k,
                'name' => SystemRoles::DEFINITIONS[$k]['name'],
                'permissions' => SystemRoles::DEFINITIONS[$k]['permissions'] === ['*'] ? array_map(fn (Permission $p) => $p->key, $permissions) : SystemRoles::DEFINITIONS[$k]['permissions'],
            ], SystemRoles::keys(Permission::PLATFORM)),
            'custom' => PlatformRole::query()->orderBy('name')->get()->map(fn (PlatformRole $r) => ['id' => $r->id, 'name' => $r->name, 'permissions' => $r->permissions])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('platform-roles.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80', 'unique:platform_roles,name'], 'permissions' => ['required', 'array', 'min:1'], 'permissions.*' => ['string']]);
        $this->roles->createPlatformRole($data['name'], array_values($data['permissions']));

        return back()->with('status', "Role “{$data['name']}” created.");
    }

    public function destroy(PlatformRole $role): RedirectResponse
    {
        Gate::authorize('platform-roles.manage');
        try {
            $this->roles->deletePlatformRole($role);
        } catch (AccessRuleViolation $e) {
            throw ValidationException::withMessages(['role' => $e->getMessage()]);
        }

        return back()->with('status', 'Role deleted.');
    }
}
