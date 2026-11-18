<?php

declare(strict_types=1);

namespace Wurk\Regions;

use Wurk\Platform\Contracts\BusinessCurrency;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Regions\Contracts\Countries;

final class RegionsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Countries::class, EloquentCountries::class);
        $this->app->scoped(BusinessCurrency::class, PackCurrency::class); // 25.1b: per request (Octane-safe)
    }

    /**
     * @return list<string|array<string, string>>
     */
    public function permissions(): array
    {
        return [['key' => 'countries.manage', 'label' => 'Manage countries, currencies and VAT', 'scope' => 'platform']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [['label' => 'Countries', 'route' => 'admin.countries', 'icon' => 'globe', 'section' => 'Settings', 'permission' => 'countries.manage', 'order' => 910]];
    }
}
