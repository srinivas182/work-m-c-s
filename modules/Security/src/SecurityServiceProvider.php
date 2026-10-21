<?php

declare(strict_types=1);

namespace Wurk\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Security\Http\Middleware\AdminIpAllowList;
use Wurk\Security\Http\Middleware\RequireTeamMfa;
use Wurk\Security\Support\Settings;

final class SecurityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'security.manage', 'label' => 'Manage platform security settings', 'scope' => 'platform'],
            ['key' => 'team-security.manage', 'label' => 'Manage team security (require authenticator)'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Security', 'route' => 'admin.security', 'icon' => 'lock-keyhole', 'section' => 'Settings', 'permission' => 'security.manage', 'order' => 940],
            ['label' => 'Team security', 'route' => 'tenant.security', 'icon' => 'lock-keyhole', 'section' => 'Settings', 'permission' => 'team-security.manage', 'order' => 795],
        ];
    }

    protected function bootModule(): void
    {
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', AdminIpAllowList::class);
        }

        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', RequireTeamMfa::class);
    }
}
