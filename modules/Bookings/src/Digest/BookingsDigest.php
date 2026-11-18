<?php

declare(strict_types=1);

namespace Wurk\Bookings\Digest;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Support\Instant;
use Wurk\Platform\Contracts\OwnerDigestSource;

/** The owner's evening summary (21.6): tomorrow's appointments and tables, and today's deposits. */
final readonly class BookingsDigest implements OwnerDigestSource
{
    public function key(): string
    {
        return 'booking';
    }

    public function figures(CarbonImmutable $today): array
    {
        $tomorrow = [Instant::db($today->addDay()), Instant::db($today->addDays(2))];

        return [
            'tomorrow_bookings' => Booking::query()->whereIn('status', Booking::ACTIVE)->where('starts_at', '>=', $tomorrow[0])->where('starts_at', '<', $tomorrow[1])->count()
                + Reservation::query()->whereIn('status', Reservation::ACTIVE)->where('starts_at', '>=', $tomorrow[0])->where('starts_at', '<', $tomorrow[1])->count(),
            'received_cents' => (int) Booking::query()->where('paid_at', '>=', Instant::db($today))->where('paid_at', '<', $tomorrow[0])->sum('deposit_cents'),
        ];
    }

    public function requests(): array
    {
        return []; // bookings don't wait for the owner's approval
    }

    public function decide(string $id, bool $yes, string $identityId, string $name): string
    {
        return '';
    }
}
