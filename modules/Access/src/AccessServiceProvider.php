<?php

declare(strict_types=1);

namespace Wurk\Access;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Events\RequestReceived;
use Wurk\Access\Console\SyncPermissionsCommand;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Access\Contracts\TeamSize;
use Wurk\Access\Http\Middleware\RequirePermission;
use Wurk\Access\Listeners\GrantAccessOnMembership;
use Wurk\Access\Models\PlatformRole;
use Wurk\Access\Permissions\PermissionRegistry;
use Wurk\Access\Support\MemberCount;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Events\StaffGranted;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Events\TenantEntered;
use Wurk\Tenancy\Events\TenantLeft;

final class AccessServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TeamSize::class, MemberCount::class);
        $this->app->singleton(TeamDirectory::class, MemberCount::class);
        $this->app->singleton(SeatPolicy::class, MemberCount::class);
        $this->app->singleton(PermissionRegistry::class);
        $this->app->singleton(AccessManager::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'platform-roles.manage', 'label' => 'Manage WURK staff roles', 'scope' => 'platform'],
            ['key' => 'roles.manage', 'label' => 'Create and change team roles'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'WURK staff', 'route' => 'admin.staff.index', 'icon' => 'user-cog', 'section' => 'Platform', 'permission' => 'staff.manage', 'order' => 60],
            ['label' => 'Staff roles', 'route' => 'admin.roles.index', 'icon' => 'key-round', 'section' => 'Platform', 'permission' => 'platform-roles.manage', 'order' => 70],
        ];
    }

    protected function bootModule(): void
    {
        // Declared permissions are answered by Access; anything else (policy
        // abilities such as "update") falls through to policies as usual.
        Gate::before(function (?Authenticatable $user, string $ability): ?bool {
            $registry = $this->app->make(PermissionRegistry::class);
            if ($user === null || ! $registry->has($ability)) {
                return null;
            }

            return $this->app->make(AccessManager::class)->allows((string) $user->getAuthIdentifier(), $ability);
        });

        Event::listen(MembershipGranted::class, [GrantAccessOnMembership::class, 'handleMembership']);
        Event::listen(StaffGranted::class, [GrantAccessOnMembership::class, 'handleStaff']);

        $flush = fn () => $this->app->make(AccessManager::class)->flush();
        $events = [TenantEntered::class, TenantLeft::class, Login::class, Logout::class, JobProcessed::class];
        if (class_exists(RequestReceived::class)) {
            $events[] = RequestReceived::class;
        }
        Event::listen($events, $flush);

        $this->app->make(Router::class)->aliasMiddleware('permission', RequirePermission::class);
        Route::model('role', PlatformRole::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncPermissionsCommand::class]);
        }
    }
}
