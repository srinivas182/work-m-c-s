<?php

declare(strict_types=1);

namespace Wurk\Plans;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Wurk\Plans\Console\SeedPlaceholderPackagesCommand;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Http\Middleware\EnsureEntitled;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Support\EloquentCatalog;
use Wurk\Plans\Support\EntitlementService;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Tenancy\Contracts\TenantContext;

final class PlansServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Entitlements::class, EntitlementService::class);
        $this->app->singleton(Catalog::class, EloquentCatalog::class);
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [
            ['key' => 'packages.view', 'label' => 'See packages and business plans', 'scope' => 'platform'],
            ['key' => 'packages.manage', 'label' => 'Create and change packages; change a business’s plan', 'scope' => 'platform'],
        ];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Packages', 'route' => 'admin.packages.index', 'icon' => 'package', 'section' => 'Platform', 'permission' => 'packages.view', 'order' => 30],
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([SeedPlaceholderPackagesCommand::class]);
        }

        Route::model('package', Package::class);
        $this->app->make(Router::class)->aliasMiddleware('entitled', EnsureEntitled::class);

        // "use-module" lets navigation and views ask whether the current business has a module.
        Gate::define('use-module', function (?Authenticatable $user, string $module): bool {
            $context = $this->app->make(TenantContext::class);

            return $context->has() && $this->app->make(Entitlements::class)->entitled($context->id(), $module);
        });
    }
}
