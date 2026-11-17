<?php

declare(strict_types=1);

namespace Wurk\Security;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Security\Contracts\PlatformSettings;
use Wurk\Security\Http\Middleware\AdminIpAllowList;
use Wurk\Security\Http\Middleware\ApplyPlatformContacts;
use Wurk\Security\Http\Middleware\LoadTestVisitors;
use Wurk\Security\Http\Middleware\RequireTeamMfa;
use Wurk\Security\Http\Middleware\SecurityHeaders;
use Wurk\Security\Support\PlatformContacts;
use Wurk\Security\Support\Settings;

final class SecurityServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Settings::class);
        $this->app->alias(Settings::class, PlatformSettings::class);
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
        // Behind a load balancer (18.1): trust its X-Forwarded-* headers — from config, so it works with a cached config.
        $proxies = trim((string) config('wurk.security.trusted_proxies', ''));
        if ($proxies !== '') {
            TrustProxies::at($proxies === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $proxies)))));
        }

        $kernel = $this->app->make(Kernel::class);
        // Security headers on every response, web or not (18.1).
        if (method_exists($kernel, 'prependMiddleware')) {
            $kernel->prependMiddleware(SecurityHeaders::class);
            $kernel->prependMiddleware(LoadTestVisitors::class); // staging load tests only (18.3b)
            $kernel->prependMiddleware(ApplyPlatformContacts::class); // WURK's contact addresses from settings (19.6)
        }
        // …and for every queued job, and commands (which boot fresh).
        Event::listen(JobProcessing::class, fn () => $this->app->make(PlatformContacts::class)->apply());
        $this->app->booted(fn () => $this->app->make(PlatformContacts::class)->apply());
        if (method_exists($kernel, 'appendMiddlewareToGroup')) {
            $kernel->appendMiddlewareToGroup('web', AdminIpAllowList::class);
        }

        $this->app->make(Router::class)->pushMiddlewareToGroup('tenant.back-office', RequireTeamMfa::class);
    }
}
