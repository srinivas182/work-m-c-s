<?php

declare(strict_types=1);

namespace Wurk\Platform;

use Illuminate\Database\Connection;
use Wurk\Platform\Console\ListModulesCommand;
use Wurk\Platform\Console\MakeModuleCommand;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\BusinessSetup;
use Wurk\Platform\Contracts\DashboardTiles;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Platform\Contracts\SalesTools;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Platform\Database\DatesPast2038Connection;
use Wurk\Platform\Modules\ModuleRegistry;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\AssistantRegistry;
use Wurk\Platform\Support\AudienceRegistry;
use Wurk\Platform\Support\BusinessSetupRegistry;
use Wurk\Platform\Support\DeletedRegistry;
use Wurk\Platform\Support\ExportRegistry;
use Wurk\Platform\Support\HealthRegistry;
use Wurk\Platform\Support\MetricsRegistry;
use Wurk\Platform\Support\MoneyOwedRegistry;
use Wurk\Platform\Support\NoSupportSessions;
use Wurk\Platform\Support\OwnerDigestRegistry;
use Wurk\Platform\Support\ReceptionistRegistry;
use Wurk\Platform\Support\ReportRegistry;
use Wurk\Platform\Support\SalesRegistry;
use Wurk\Platform\Support\TileRegistry;

/**
 * Registered in bootstrap/providers.php. Boots the Platform module itself and then
 * registers every other installed module in dependency order.
 */
final class PlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        // Year 2038 (18.4b): MySQL timestamp columns are created as DATETIME.
        Connection::resolverFor('mysql', fn ($pdo, string $database, string $prefix, array $config) => new DatesPast2038Connection($pdo, $database, $prefix, $config));
        $this->app->singleton(DashboardTiles::class, TileRegistry::class);
        $this->app->singleton(BusinessReports::class, ReportRegistry::class);
        $this->app->singleton(PlatformMetrics::class, MetricsRegistry::class);
        $this->app->singleton(AudienceSegments::class, AudienceRegistry::class);
        $this->app->singleton(AssistantTools::class, AssistantRegistry::class);
        $this->app->singleton(ReceptionistTools::class, ReceptionistRegistry::class); // 19.2
        $this->app->singleton(OwnerDigest::class, OwnerDigestRegistry::class); // 21.6
        $this->app->singleton(BusinessSetup::class, BusinessSetupRegistry::class); // 23.1
        $this->app->singleton(MoneyOwed::class, MoneyOwedRegistry::class); // 23.5
        $this->app->singleton(SalesTools::class, SalesRegistry::class); // 19.5
        $this->app->singleton(HealthSignals::class, HealthRegistry::class);
        $this->app->singleton(DataExports::class, ExportRegistry::class);
        $this->app->singleton(RecentlyDeleted::class, DeletedRegistry::class);
        $this->app->bind(SupportSessions::class, NoSupportSessions::class); // the Support module answers for real

        $this->app->singleton(
            ModuleRegistry::class,
            fn (): ModuleRegistry => new ModuleRegistry($this->app->basePath('modules')),
        );

        $registry = $this->app->make(ModuleRegistry::class);

        foreach ($registry->all() as $manifest) {
            if ($manifest->key === 'platform') {
                continue;
            }

            if (! class_exists($manifest->providerClass())) {
                throw new \RuntimeException("Module [{$manifest->name}] has no autoloadable provider {$manifest->providerClass()}. Run `composer dump-autoload`.");
            }

            $this->app->register($manifest->providerClass());
        }
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [
            ['label' => 'Design system', 'route' => 'platform.design', 'icon' => 'palette', 'section' => 'Platform', 'order' => 900],
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListModulesCommand::class,
                MakeModuleCommand::class,
            ]);
        }
    }
}
