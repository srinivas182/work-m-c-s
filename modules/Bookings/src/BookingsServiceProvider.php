<?php

declare(strict_types=1);

namespace Wurk\Bookings;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Wurk\Billing\Contracts\DepositPaid;
use Wurk\Bookings\Console\DemoBookingsCommand;
use Wurk\Bookings\Console\ExpireHoldsCommand;
use Wurk\Bookings\Support\Deposits;
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
        // A deposit Paystack reported paid: confirmed only after verifying (Deposits::confirm).
        Event::listen(DepositPaid::class, fn (DepositPaid $e) => $this->app->make(Deposits::class)->confirm($e->reference));
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bookings:expire-holds')->everyMinute()->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([DemoBookingsCommand::class, ExpireHoldsCommand::class]);
        }
    }
}
