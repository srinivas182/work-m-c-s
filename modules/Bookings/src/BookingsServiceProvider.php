<?php

declare(strict_types=1);

namespace Wurk\Bookings;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Bookings\Console\DemoBookingsCommand;
use Wurk\Bookings\Console\ExpireHoldsCommand;
use Wurk\Bookings\Console\SendRemindersCommand;
use Wurk\Bookings\Contracts\HealthPracticeStatus;
use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Events\ClassLeft;
use Wurk\Bookings\Events\ReservationCancelled;
use Wurk\Bookings\Events\ReservationConfirmed;
use Wurk\Bookings\Listeners\SendBookingMessages;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Platform\Modules\ModuleServiceProvider;

final class BookingsServiceProvider extends ModuleServiceProvider
{
    public function permissions(): array
    {
        return [
            ['key' => 'bookings.view', 'label' => 'See the bookings calendar'],
            ['key' => 'bookings.manage', 'label' => 'Manage team hours, time off, classes and bookings'],
            ['key' => 'bookings.settings', 'label' => 'Change services, prices and booking settings for the whole business'],
        ];
    }

    public function menu(): array
    {
        return [
            ['label' => 'Calendar', 'route' => 'tenant.bookings.calendar', 'icon' => 'calendar', 'section' => 'Bookings', 'permission' => 'bookings.view', 'entitlement' => 'bookings', 'order' => 200],
            ['label' => 'Classes', 'route' => 'tenant.bookings.classes', 'icon' => 'calendar', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 203],
            ['label' => 'Tables', 'route' => 'tenant.bookings.tables', 'icon' => 'users', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 205],
            ['label' => 'Booking settings', 'route' => 'tenant.bookings.settings', 'icon' => 'settings', 'section' => 'Bookings', 'permission' => 'bookings.manage', 'entitlement' => 'bookings', 'order' => 210],
        ];
    }

    public function register(): void
    {
        parent::register();
        $this->app->bind(HealthPracticeStatus::class, HealthPractice::class);
    }

    protected function bootModule(): void
    {
        $this->loadViewsFrom($this->modulePath('resources/views'), 'bookings');
        // Public limits per business AND visitor: the route's domain pattern is the same for every business,
        // so keying by it alone made all of WURK share one allowance per IP (carrier NAT puts many
        // customers behind one IP).
        $visitor = fn (Request $r, string $name) => $name.'|'.(string) $r->route('handle').'|'.$r->ip();
        RateLimiter::for('book-create', fn (Request $r) => Limit::perMinutes(10, 10)->by($visitor($r, 'book-create')));
        RateLimiter::for('book-read', fn (Request $r) => Limit::perMinute(60)->by($visitor($r, 'book-read')));
        RateLimiter::for('book-manage', fn (Request $r) => Limit::perMinute(30)->by($visitor($r, 'book-manage')));
        // A deposit Paystack reported paid: confirmed only after verifying (Deposits::confirm).
        // A provider reported a payment: deposits and class places ("dep_…") are confirmed after verifying it.
        Event::listen(ContactsBackfillRequested::class, function (): void { // existing customers → contacts
            $book = app(ContactBook::class);
            Booking::query()->orderBy('id')->chunk(500, function ($rows) use ($book): void {
                foreach ($rows as $b) {
                    $book->remember($b->customer_name, $b->customer_phone, $b->customer_email, 'booking', $b->id, $b->branch_id, 'Booking · '.$b->starts_at);
                }
            });
            ClassAttendee::query()->orderBy('id')->chunk(500, function ($rows) use ($book): void {
                foreach ($rows as $a) {
                    $book->remember($a->customer_name, $a->customer_phone, $a->customer_email, 'class', $a->id, null, 'Class place');
                }
            });
        });
        Event::listen(BusinessPaymentReceived::class, fn (BusinessPaymentReceived $e) => str_starts_with($e->reference, 'dep_') ? $this->app->make(Deposits::class)->confirm($e->reference) : null);
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
