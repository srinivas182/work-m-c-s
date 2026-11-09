<?php

declare(strict_types=1);

namespace Wurk\Plans;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Wurk\Access\Contracts\SeatPolicy;
use Wurk\Plans\Console\SeedPlaceholderPackagesCommand;
use Wurk\Plans\Console\SnapshotMrrCommand;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Plans\Contracts\UsageMeter;
use Wurk\Plans\Http\Middleware\EnsureEntitled;
use Wurk\Plans\Metrics\MovementSection;
use Wurk\Plans\Metrics\SubscriptionsSection;
use Wurk\Plans\Models\Package;
use Wurk\Plans\Support\EloquentCatalog;
use Wurk\Plans\Support\EntitlementService;
use Wurk\Plans\Support\PackageSeatPolicy;
use Wurk\Plans\Support\StaffMeter;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

final class PlansServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->tag([StaffMeter::class], UsageMeter::TAG);
        $this->app->singleton(SeatPolicy::class, PackageSeatPolicy::class); // replaces Access's default (no limit)
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
        // WURK's SaaS metrics (Sprint 15.4a): the daily subscription snapshot, and Admin → Metrics → Subscriptions.
        if ($this->app->runningInConsole()) {
            $this->commands([SnapshotMrrCommand::class]);
        }
        $this->callAfterResolving(Schedule::class, fn (Schedule $s) => $s->command('plans:snapshot-mrr')->dailyAt('23:50')->onOneServer()->withoutOverlapping());
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('subscriptions', 100, fn (ReportPeriod $p) => Gate::allows('metrics.view')
                ? app(SubscriptionsSection::class)->build($p) : null);
        });
        $this->callAfterResolving(PlatformMetrics::class, function (PlatformMetrics $m): void {
            $m->register('movement', 150, fn (ReportPeriod $p) => Gate::allows('metrics.view')
                ? app(MovementSection::class)->build($p) : null);
        });
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
