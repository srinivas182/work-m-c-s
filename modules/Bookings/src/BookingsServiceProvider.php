<?php

declare(strict_types=1);

namespace Wurk\Bookings;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Wurk\Billing\Contracts\DepositPaid;
use Wurk\Bookings\Console\DemoBookingsCommand;
use Wurk\Bookings\Console\ExpireHoldsCommand;
use Wurk\Bookings\Console\SendRemindersCommand;
use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Events\ClassLeft;
use Wurk\Bookings\Events\ReservationCancelled;
use Wurk\Bookings\Events\ReservationConfirmed;
use Wurk\Bookings\Listeners\SendBookingMessages;
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
            ['label' => 'Tables', 'route' => 'tenant.bookings.tables', 'icon' => 'users', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 205],
            ['label' => 'Booking settings', 'route' => 'tenant.bookings.settings', 'icon' => 'settings', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 210],
        ];
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'bookings');
        // A deposit Paystack reported paid: confirmed only after verifying (Deposits::confirm).
        Event::listen(DepositPaid::class, fn (DepositPaid $e) => $this->app->make(Deposits::class)->confirm($e->reference));
        Event::listen([BookingConfirmed::class, BookingMoved::class, BookingCancelled::class, ReservationConfirmed::class, ReservationCancelled::class, ClassJoined::class, ClassLeft::class], SendBookingMessages::class);
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bookings:expire-holds')->everyMinute()->onOneServer()->withoutOverlapping();
            $schedule->command('bookings:send-reminders')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([DemoBookingsCommand::class, ExpireHoldsCommand::class, SendRemindersCommand::class]);
        }
    }
}
