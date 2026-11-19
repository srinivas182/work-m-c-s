<?php

declare(strict_types=1);

namespace Wurk\Reach;

use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Reach\Support\ReachNumbers;

/**
 * Reach (Sprint 25.2): customers book from any phone, without data — by USSD (one WURK code, a short number per
 * business) and by SMS. The menu uses the AI receptionist's booking tools, so the rules are the website's.
 */
final class ReachServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ReachNumbers::class);
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadViewsFrom($this->modulePath('resources/views'), 'reach');
    }

    public function permissions(): array
    {
        return [['key' => 'reach.manage', 'label' => 'Bookings by phone: USSD and SMS settings']];
    }

    /**
     * @return list<array{label: string, route: string, icon?: string, section?: string, permission?: string, order?: int}>
     */
    public function menu(): array
    {
        return [['label' => 'Bookings by phone', 'route' => 'tenant.reach.settings', 'icon' => 'phone', 'section' => 'Settings', 'permission' => 'reach.manage', 'order' => 835]];
    }
}
