<?php

declare(strict_types=1);

namespace Wurk\Bookings;

use Wurk\Bookings\Console\DemoBookingsCommand;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class BookingsServiceProvider extends ModuleServiceProvider
{
    public function permissions(): array
    {
        return [
            ['key' => 'bookings.view', 'label' => 'See the bookings calendar'],
            ['key' => 'bookings.manage', 'label' => 'Manage services, team hours, time off and bookings'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Calendar', 'route' => 'tenant.bookings.calendar', 'icon' => 'calendar', 'section' => 'Bookings', 'permission' => 'bookings.view', 'entitlement' => 'bookings', 'order' => 200],
            ['label' => 'Booking settings', 'route' => 'tenant.bookings.settings', 'icon' => 'settings', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 210],
        ];
    }

    protected function bootModule(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([DemoBookingsCommand::class]);
        }
    }
}
