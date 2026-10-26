<?php

declare(strict_types=1);

namespace Wurk\Bookings\Listeners;

use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Events\ClassLeft;
use Wurk\Bookings\Events\ReservationCancelled;
use Wurk\Bookings\Events\ReservationConfirmed;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Bookings\Support\Meetings;
use Wurk\Bookings\Support\Reminders;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Confirmation, change and cancellation messages, and (re)scheduling reminders. */
final readonly class SendBookingMessages
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private BookingMessages $messages, private Reminders $reminders, private Meetings $meetings) {}

    public function handle(BookingConfirmed|BookingMoved|BookingCancelled|ReservationConfirmed|ReservationCancelled|ClassJoined|ClassLeft $event): void
    {
        if ($event instanceof ClassJoined || $event instanceof ClassLeft) {
            $this->classAttendee($event);

            return;
        }
        if ($event instanceof ReservationConfirmed || $event instanceof ReservationCancelled) {
            $this->reservation($event);

            return;
        }
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
                $this->meetings->ensure($booking); // the link first, so the confirmation carries it
                $this->messages->send($booking->refresh(), 'confirmed');
                $this->reminders->schedule($booking);
            } elseif ($event instanceof BookingMoved) {
                $this->meetings->moved($booking);
                $this->messages->send($booking, 'moved', 'moved_'.$booking->starts_at->getTimestamp());
                $this->reminders->schedule($booking);
            } else {
                $this->meetings->cancelled($booking);
                $this->messages->send($booking, 'cancelled');
                $this->reminders->clear($booking->id);
            }
        });
    }

    private function reservation(ReservationConfirmed|ReservationCancelled $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($event): void {
            $reservation = Reservation::query()->whereKey($event->reservationId)->first();
            if ($reservation === null) {
                return;
            }
            if ($event instanceof ReservationConfirmed) {
                $this->messages->send($reservation, 'confirmed');
                $this->reminders->schedule($reservation);
            } else {
                $this->messages->send($reservation, 'cancelled');
                $this->reminders->clear($reservation->id);
            }
        });
    }

    private function classAttendee(ClassJoined|ClassLeft $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($event): void {
            $attendee = ClassAttendee::query()->whereKey($event->attendeeId)->first();
            if ($attendee === null) {
                return;
            }
            if ($event instanceof ClassJoined) {
                $this->meetings->ensureForClass($attendee->session()); // one link for the whole class
                $this->messages->send($attendee, 'confirmed');
                $this->reminders->schedule($attendee);
            } else {
                $this->messages->send($attendee, 'cancelled');
                $this->reminders->clear($attendee->id);
            }
        });
    }
}
