<?php

declare(strict_types=1);

namespace Wurk\Tenancy;

use Closure;
use Illuminate\Log\Context\Repository as LogContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Routing\RouteRegistrar;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Events\RequestTerminated;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Console\CreateTenantCommand;
use Wurk\Tenancy\Console\MigrateShardsCommand;
use Wurk\Tenancy\Console\RegisterShardCommand;
use Wurk\Tenancy\Console\RunForTenantsCommand;
use Wurk\Tenancy\Context\TenantContextManager;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;
use Wurk\Tenancy\Http\Middleware\InitializeTenancyByDomain;
use Wurk\Tenancy\Http\Middleware\InitializeTenancyByPath;
use Wurk\Tenancy\Listeners\ForgetTenantContext;
use Wurk\Tenancy\Models\Branch;
use Wurk\Tenancy\Models\Tenant;
use Wurk\Tenancy\Policies\BranchPolicy;
use Wurk\Tenancy\Policies\TenantPolicy;
use Wurk\Tenancy\Resolution\EloquentTenantDirectory;
use Wurk\Tenancy\Support\HandleRules;

final class TenancyServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, TenantContextManager::class);
        $this->app->singleton(TenantDirectory::class, EloquentTenantDirectory::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'tenants.view', 'label' => 'See businesses', 'scope' => 'platform'],
            ['key' => 'tenants.manage', 'label' => 'Create, suspend and change businesses', 'scope' => 'platform'],
            ['key' => 'shards.manage', 'label' => 'Manage tenant databases', 'scope' => 'platform'],
            ['key' => 'branches.view', 'label' => 'See branches'],
            ['key' => 'branches.manage', 'label' => 'Add and change branches'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Businesses', 'route' => 'admin.businesses.index', 'icon' => 'building', 'section' => 'Platform', 'permission' => 'tenants.view', 'order' => 20],
        ];
    }

    protected function bootModule(): void
    {
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Branch::class, BranchPolicy::class);

        $this->registerRouteMacros();
        $this->resetContextBetweenUnitsOfWork();
        $this->restoreContextInQueuedJobs();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RegisterShardCommand::class,
                MigrateShardsCommand::class,
                CreateTenantCommand::class,
                RunForTenantsCommand::class,
            ]);
        }
    }

    /**
     * Route::tenant(fn () => …)       app.{root}/{tenant}/…  (business back office; signed-in
     *                                   users only, checked before the business is looked up so
     *                                   unauthenticated visitors cannot probe which handles exist)
     * Route::tenantSite(fn () => …)   {handle}.{root} or custom domain (public site)
     */
    private function registerRouteMacros(): void
    {
        Route::macro('tenant', function (Closure $routes, bool $requiresAuth = true): RouteRegistrar {
            $registrar = Route::domain((string) config('wurk.domains.app'))
                ->prefix('{tenant}')
                ->where(['tenant' => HandleRules::routePattern()])
                ->middleware($requiresAuth ? ['web', 'auth', InitializeTenancyByPath::class] : ['web', InitializeTenancyByPath::class]);
            $registrar->group($routes);

            return $registrar;
        });

        Route::macro('tenantSite', function (Closure $routes): RouteRegistrar {
            $registrar = Route::middleware(['web', InitializeTenancyByDomain::class]);
            $registrar->group($routes);

            return $registrar;
        });
    }

    private function resetContextBetweenUnitsOfWork(): void
    {
        $events = [JobProcessed::class, JobExceptionOccurred::class];

        if (class_exists(RequestReceived::class)) {
            $events[] = RequestReceived::class;
            $events[] = RequestTerminated::class;
        }

        Event::listen($events, ForgetTenantContext::class);
    }

    /**
     * The tenant id travels inside Laravel's job context; when a worker picks the
     * job up, enter that tenant before the job runs.
     */
    private function restoreContextInQueuedJobs(): void
    {
        Context::hydrated(function (LogContext $context): void {
            $tenantId = $context->get(TenantContextManager::CONTEXT_KEY);

            if (! is_string($tenantId)) {
                return;
            }

            $tenant = Tenant::query()->find($tenantId);

            if ($tenant !== null) {
                $this->app->make(TenantContext::class)->enter($tenant);
            }
        });
    }
}
