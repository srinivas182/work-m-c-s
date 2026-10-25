<?php

declare(strict_types=1);

namespace Wurk\Bookings\Listeners;

use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Bookings\Support\Reminders;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Confirmation, change and cancellation messages, and (re)scheduling reminders. */
final readonly class SendBookingMessages
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private BookingMessages $messages, private Reminders $reminders) {}

    public function handle(BookingConfirmed|BookingMoved|BookingCancelled $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($event): void {
            $booking = Booking::query()->whereKey($event->bookingId)->first();
            if ($booking === null) {
                return;
            }
            if ($event instanceof BookingConfirmed) {
                $this->messages->send($booking, 'confirmed');
                $this->reminders->schedule($booking);
            } elseif ($event instanceof BookingMoved) {
                $this->messages->send($booking, 'moved', 'moved_'.$booking->starts_at->getTimestamp());
                $this->reminders->schedule($booking);
            } else {
                $this->messages->send($booking, 'cancelled');
                $this->reminders->clear($booking->id);
            }
        });
    }
}
