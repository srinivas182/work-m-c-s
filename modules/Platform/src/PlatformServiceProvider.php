<?php

declare(strict_types=1);

namespace Wurk\Platform;

use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Connection;
use Illuminate\Routing\Middleware\ValidateSignature;
use Wurk\Platform\Console\ListModulesCommand;
use Wurk\Platform\Console\MakeModuleCommand;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\AudienceSegments;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\BusinessSetup;
use Wurk\Platform\Contracts\DashboardTiles;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Contracts\FinishedVisits;
use Wurk\Platform\Contracts\HealthSignals;
use Wurk\Platform\Contracts\LoyaltyCards;
use Wurk\Platform\Contracts\MoneyOwed;
use Wurk\Platform\Contracts\NeedsYouNow;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\PlatformMetrics;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Platform\Contracts\SalesTools;
use Wurk\Platform\Contracts\SupportSessions;
use Wurk\Platform\Contracts\TeamHours;
use Wurk\Platform\Contracts\TeamTakings;
use Wurk\Platform\Contracts\TodayList;
use Wurk\Platform\Database\AppTimezoneSQLiteConnection;
use Wurk\Platform\Database\DatesPast2038Connection;
use Wurk\Platform\I18n\StoreAwareLoader;
use Wurk\Platform\Modules\ModuleRegistry;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\AssistantRegistry;
use Wurk\Platform\Support\AudienceRegistry;
use Wurk\Platform\Support\BusinessSetupRegistry;
use Wurk\Platform\Support\DeletedRegistry;
use Wurk\Platform\Support\ExportRegistry;
use Wurk\Platform\Support\FinishedVisitsRegistry;
use Wurk\Platform\Support\HealthRegistry;
use Wurk\Platform\Support\MetricsRegistry;
use Wurk\Platform\Support\MoneyOwedRegistry;
use Wurk\Platform\Support\NeedsYouRegistry;
use Wurk\Platform\Support\NoLoyaltyCards;
use Wurk\Platform\Support\NoPushNotifications;
use Wurk\Platform\Support\NoSupportSessions;
use Wurk\Platform\Support\OwnerDigestRegistry;
use Wurk\Platform\Support\ReceptionistRegistry;
use Wurk\Platform\Support\ReportRegistry;
use Wurk\Platform\Support\SalesRegistry;
use Wurk\Platform\Support\TeamHoursRegistry;
use Wurk\Platform\Support\TeamTakingsRegistry;
use Wurk\Platform\Support\TileRegistry;
use Wurk\Platform\Support\TodayRegistry;

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
        // Dates bound to queries are moved to the app's zone first, as stored (fix, 18 Nov 2026).
        Connection::resolverFor('sqlite', fn ($pdo, string $database, string $prefix, array $config) => new AppTimezoneSQLiteConnection($pdo, $database, $prefix, $config));
        $this->app->singleton(DashboardTiles::class, TileRegistry::class);
        $this->app->singleton(TodayList::class, TodayRegistry::class); // 26.2: the team's Today
        $this->app->singleton(NeedsYouNow::class, NeedsYouRegistry::class); // 26.3: Home's "Needs you now"
        $this->app->singleton(TeamHours::class, TeamHoursRegistry::class); // 26.4: the roster
        $this->app->singleton(TeamTakings::class, TeamTakingsRegistry::class); // 26.4: commission
        $this->app->singleton(BusinessReports::class, ReportRegistry::class);
        $this->app->singleton(PlatformMetrics::class, MetricsRegistry::class);
        $this->app->singleton(AudienceSegments::class, AudienceRegistry::class);
        $this->app->singleton(AssistantTools::class, AssistantRegistry::class);
        $this->app->singleton(ReceptionistTools::class, ReceptionistRegistry::class); // 19.2
        $this->app->singleton(OwnerDigest::class, OwnerDigestRegistry::class); // 21.6
        $this->app->singleton(BusinessSetup::class, BusinessSetupRegistry::class); // 23.1
        $this->app->singleton(MoneyOwed::class, MoneyOwedRegistry::class); // 23.5
        $this->app->singleton(FinishedVisits::class, FinishedVisitsRegistry::class); // 25.3: who to ask for a review
        $this->app->extend('translation.loader', fn (Loader $loader) => new StoreAwareLoader($loader)); // reviewed translations over the files (24.2)
        $this->app->singleton(SalesTools::class, SalesRegistry::class); // 19.5
        $this->app->singleton(HealthSignals::class, HealthRegistry::class);
        $this->app->singleton(DataExports::class, ExportRegistry::class);
        $this->app->singleton(RecentlyDeleted::class, DeletedRegistry::class);
        $this->app->bind(SupportSessions::class, NoSupportSessions::class); // the Support module answers for real
        $this->app->bind(PushNotifications::class, NoPushNotifications::class); // the Push module sends for real (26.2)
        $this->app->bind(LoyaltyCards::class, NoLoyaltyCards::class); // the Loyalty module for real (26.5)

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
        $this->loadViewsFrom($this->modulePath('resources/views'), 'platform');
        // A signed link stays valid when the customer picks their language (24.3) or it's tagged by where it was shared (23.3).
        ValidateSignature::except(['lang', 'utm_source']);
        if ($this->app->runningInConsole()) {
            $this->commands([
                ListModulesCommand::class,
                MakeModuleCommand::class,
            ]);
        }
    }
}
