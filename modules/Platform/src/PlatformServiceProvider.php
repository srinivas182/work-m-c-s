<?php

declare(strict_types=1);

namespace Wurk\Platform;

use Wurk\Platform\Console\ListModulesCommand;
use Wurk\Platform\Console\MakeModuleCommand;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\DashboardTiles;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Platform\Modules\ModuleRegistry;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\AssistantRegistry;
use Wurk\Platform\Support\AudienceRegistry;
use Wurk\Platform\Support\HealthRegistry;
use Wurk\Platform\Support\MetricsRegistry;
use Wurk\Platform\Support\NoSupportSessions;
use Wurk\Platform\Support\ReportRegistry;
use Wurk\Platform\Support\TileRegistry;

/**
 * Registered in bootstrap/providers.php. Boots the Platform module itself and then
 * registers every other installed module in dependency order.
 */
final class PlatformServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DashboardTiles::class, TileRegistry::class);
        $this->app->singleton(BusinessReports::class, ReportRegistry::class);
        $this->app->singleton(PlatformMetrics::class, MetricsRegistry::class);
        $this->app->singleton(AudienceSegments::class, AudienceRegistry::class);
        $this->app->singleton(AssistantTools::class, AssistantRegistry::class);
        $this->app->singleton(HealthSignals::class, HealthRegistry::class);
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
