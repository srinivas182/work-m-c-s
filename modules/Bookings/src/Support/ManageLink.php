<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Facades\URL;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\Reservation;

/** A signed link for the customer to change or cancel a booking — no account; it expires when the booking starts. */
final class ManageLink
{
    public static function for(Booking $booking, string $handle): string
    {
        return URL::temporarySignedRoute('bookings.public.manage', $booking->starts_at, ['handle' => $handle, 'booking' => $booking->id]);
    }

    public static function forTable(Reservation $reservation, string $handle): string
    {
        return URL::temporarySignedRoute('bookings.public.table', $reservation->starts_at, ['handle' => $handle, 'reservation' => $reservation->id]);
    }
}
