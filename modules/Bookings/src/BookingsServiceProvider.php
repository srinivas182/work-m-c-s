<?php

declare(strict_types=1);

namespace Wurk\Bookings;

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
            ['label' => 'Booking settings', 'route' => 'tenant.bookings.settings', 'icon' => 'calendar', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 210],
        ];
    }
}
