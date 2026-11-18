<?php

declare(strict_types=1);

namespace Wurk\Bookings;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Assistant\AddBookingHoursTool;
use Wurk\Bookings\Assistant\BookingsOnDayTool;
use Wurk\Bookings\Console\DemoBookingsCommand;
use Wurk\Bookings\Console\ExpireHoldsCommand;
use Wurk\Bookings\Console\SendRemindersCommand;
use Wurk\Bookings\Contracts\HealthPracticeStatus;
use Wurk\Bookings\Digest\BookingsDigest;
use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Events\ClassLeft;
use Wurk\Bookings\Events\ReservationCancelled;
use Wurk\Bookings\Events\ReservationConfirmed;
use Wurk\Bookings\Listeners\ConfirmPaymentOnWhatsApp;
use Wurk\Bookings\Listeners\SendBookingMessages;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Receptionist\BookAppointmentTool;
use Wurk\Bookings\Receptionist\CancelBookingTool;
use Wurk\Bookings\Receptionist\ClassesTool;
use Wurk\Bookings\Receptionist\ConfirmBookingCodeTool;
use Wurk\Bookings\Receptionist\FindBookingTool;
use Wurk\Bookings\Receptionist\FreeTimesTool;
use Wurk\Bookings\Receptionist\JoinClassTool;
use Wurk\Bookings\Receptionist\MoveBookingTool;
use Wurk\Bookings\Receptionist\ReserveTableTool;
use Wurk\Bookings\Receptionist\ServicesTool;
use Wurk\Bookings\Receptionist\TableTimesTool;
use Wurk\Bookings\Reports\BookingsSection;
use Wurk\Bookings\Setup\BookingsSetupStep;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Bookings\Support\MessageWording;
use Wurk\Bookings\WhatsApp\ReminderButtons;
use Wurk\Crm\Contracts\ContactBook;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Messaging\Contracts\MessageTemplates;
use Wurk\Messaging\Contracts\WhatsAppActions;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Platform\Contracts\AssistantTools;
use Wurk\Platform\Contracts\BusinessReports;
use Wurk\Platform\Contracts\BusinessSetup;
use Wurk\Platform\Contracts\CannotRestore;
use Wurk\Platform\Contracts\DataExports;
use Wurk\Platform\Contracts\OwnerDigest;
use Wurk\Platform\Contracts\RecentlyDeleted;
use Wurk\Platform\Contracts\ReceptionistTools;
use Wurk\Platform\Modules\ModuleServiceProvider;
use Wurk\Platform\Support\ReportPeriod;
use Wurk\Tenancy\Contracts\TenantContext;

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
        $this->callAfterResolving(MessageTemplates::class, function (MessageTemplates $t): void {
            foreach (MessageWording::definitions() as $key => $def) {
                $t->define($key, $def); // Settings → Messages
            }
        });
        $this->app->bind(HealthPracticeStatus::class, HealthPractice::class);
    }

    protected function bootModule(): void
    {
        $this->callAfterResolving(OwnerDigest::class, fn (OwnerDigest $d) => $d->register(BookingsDigest::class)); // the owner's evening WhatsApp summary (21.6)
        $this->callAfterResolving(BusinessSetup::class, fn (BusinessSetup $s) => $s->register(BookingsSetupStep::class)); // set up by talking (23.1)
        // Recently deleted (16.6b): services. One with bookings is kept (hidden) after 30 days, so those bookings keep its name.
        $this->callAfterResolving(RecentlyDeleted::class, fn (RecentlyDeleted $d) => $d->register('services', 'Booking services', 'bookings.settings',
            fn (\DateTimeInterface $since) => BookingService::onlyTrashed()->where('deleted_at', '>', $since)->orderByDesc('deleted_at')->get()
                ->map(fn (BookingService $s) => ['id' => $s->id, 'name' => $s->name, 'deleted_at' => $s->deleted_at ?? now()])->all(),
            function (string $id): string {
                $s = BookingService::onlyTrashed()->whereKey($id)->first() ?? throw new CannotRestore('That service isn’t in Recently deleted any more.');
                $s->restore();
                $this->app->make(AuditLog::class)->record('bookings.service_restored', 'service', $s->id, ['name' => $s->name]);

                return "“{$s->name}” is back on your booking page".($s->active ? '.' : ' list (switched off — switch it on to take bookings).');
            },
            function (\DateTimeInterface $before): int {
                $ctx = $this->app->make(TenantContext::class);
                $ids = BookingService::onlyTrashed()->where('deleted_at', '<', $before)
                    ->whereNotExists(fn ($q) => $q->from('bookings')->where('bookings.tenant_id', $ctx->id())->whereColumn('bookings.service_id', 'booking_services.id'))->pluck('id')->all();
                DB::connection($ctx->connectionName())->table('booking_service_member')->where('tenant_id', $ctx->id())->whereIn('service_id', $ids)->delete();

                return (int) BookingService::onlyTrashed()->whereIn('id', $ids)->forceDelete();
            },
        ));
        // Recently deleted (16.6c): cancelled bookings still ahead can be put back while their time is free. Nothing to purge —
        // a cancelled booking stays in the history; it just leaves this list after 30 days.
        $this->callAfterResolving(RecentlyDeleted::class, fn (RecentlyDeleted $d) => $d->register('bookings', 'Cancelled bookings', 'bookings.manage',
            function (\DateTimeInterface $since): array {
                $tz = $this->app->make(TenantContext::class)->current()->timezone();
                $bookings = Booking::query()->where('status', 'cancelled')->where('cancelled_at', '>', $since)->where('starts_at', '>', now())->orderByDesc('cancelled_at')->limit(200)->get();
                $names = BookingService::withTrashed()->whereIn('id', $bookings->pluck('service_id')->unique())->pluck('name', 'id');

                return $bookings->map(fn (Booking $b) => ['id' => $b->id, 'deleted_at' => $b->cancelled_at ?? now(),
                    'name' => ($names[$b->service_id] ?? 'Booking').' — '.$b->customer_name.', '.$b->starts_at->setTimezone($tz)->format('D j M H:i')])->all();
            },
            function (string $id): string {
                $b = Booking::query()->whereKey($id)->first() ?? throw new CannotRestore('That booking isn’t there any more.');
                $this->app->make(Booker::class)->restore($b, auth()->id() === null ? null : (string) auth()->id());

                return "{$b->customer_name}’s booking is back on, and they’ve been told.";
            },
            fn (\DateTimeInterface $before) => 0,
        ));
        // Your data (16.6): what this module keeps, for the full export.
        $this->callAfterResolving(DataExports::class, function (DataExports $x): void {
            $rows = fn (string $table, string $order) => fn () => DB::connection(app(TenantContext::class)->connectionName())->table($table)
                ->where('tenant_id', app(TenantContext::class)->id())->orderBy($order)->lazy(500)->map(fn ($r) => (array) $r);
            $x->table('services', 'Services and prices', 'bookings.view', $rows('booking_services', 'position'));
            $x->table('bookings', 'Bookings', 'bookings.view', $rows('bookings', 'starts_at'));
        });
        // Reports → Bookings (Sprint 15.1a).
        // The AI receptionist (19.2b, c): services, free times, booking; finding, moving and cancelling; classes and tables.
        $this->callAfterResolving(ReceptionistTools::class, function (ReceptionistTools $t): void {
            foreach ([ServicesTool::class, FreeTimesTool::class, BookAppointmentTool::class, MoveBookingTool::class, CancelBookingTool::class, FindBookingTool::class, ConfirmBookingCodeTool::class,
                ClassesTool::class, JoinClassTool::class, TableTimesTool::class, ReserveTableTool::class] as $tool) {
                $t->register($tool);
            }
        });
        // 21.5: the Confirm / Move / Cancel buttons on reminders from the business's WhatsApp number.
        $this->callAfterResolving(WhatsAppActions::class, fn (WhatsAppActions $a) => $a->register(ReminderButtons::class));
        $this->callAfterResolving(AssistantTools::class, function (AssistantTools $t): void {
            $t->register(BookingsOnDayTool::class);
            $t->register(AddBookingHoursTool::class);
        });
        $this->callAfterResolving(BusinessReports::class, function (BusinessReports $r): void {
            $r->register('bookings', 200, fn (ReportPeriod $p) => ($p->forOwners || Gate::allows('reports.view'))
                && app(Entitlements::class)->entitled(app(TenantContext::class)->id(), 'bookings')
                ? app(BookingsSection::class)->build($p) : null);
        });
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
        Event::listen([BookingConfirmed::class, ClassJoined::class], ConfirmPaymentOnWhatsApp::class); // 21.5
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('bookings:expire-holds')->everyMinute()->onOneServer()->withoutOverlapping();
            $schedule->command('bookings:send-reminders')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
        });
        if ($this->app->runningInConsole()) {
            $this->commands([DemoBookingsCommand::class, ExpireHoldsCommand::class, SendRemindersCommand::class]);
        }
    }
}
