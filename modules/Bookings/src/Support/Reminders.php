<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Facades\DB;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\Reservation;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Reminders 24 hours and/or 2 hours before (the owner's choice), kept in a central list of due reminders. */
final readonly class Reminders
{
    public function __construct(private TenantContext $context, private BookingMessages $messages) {}

    /** (Re)schedule a booking's reminders — on confirmation and when it moves. */
    public function schedule(Booking|Reservation $booking): void
    {
        $this->clear($booking->id);
        $settings = BookingSettings::query()->find($this->context->id());
        $offsets = array_filter(['24h' => $settings === null || $settings->remind_24h ? 24 : null, '2h' => $settings !== null && $settings->remind_2h ? 2 : null]);
        foreach ($offsets as $label => $hours) {
            $due = $booking->starts_at->subHours($hours);
            if ($due->isFuture()) {
                $prefix = $booking instanceof Reservation ? 'table_' : '';
                DB::table('booking_reminders')->insert(['booking_id' => $booking->id, 'kind' => "{$prefix}reminder_{$label}_{$booking->starts_at->getTimestamp()}",
                    'tenant_id' => $this->context->id(), 'due_at' => Instant::db($due)]);
            }
        }
    }

    public function clear(string $bookingId): void
    {
        DB::table('booking_reminders')->where('booking_id', $bookingId)->delete();
    }

    /** Send reminders that are due (every 5 minutes, across businesses). */
    public function sendDue(): int
    {
        $sent = 0;
        $tenants = app(TenantDirectory::class);
        foreach (DB::table('booking_reminders')->where('due_at', '<=', Instant::db(now()))->get()->groupBy('tenant_id') as $tenantId => $due) {
            $tenant = $tenants->find((string) $tenantId);
            if ($tenant !== null) {
                $sent += (int) $this->context->run($tenant, function () use ($due): int {
                    $n = 0;
                    foreach ($due as $r) {
                        $booking = str_starts_with((string) $r->kind, 'table_')
                            ? Reservation::query()->whereKey($r->booking_id)->first()
                            : Booking::query()->whereKey($r->booking_id)->first();
                        if ($booking !== null && $booking->status === 'confirmed' && $booking->starts_at->isFuture()) {
                            if ($booking instanceof Booking) {
                                app(Meetings::class)->ensure($booking); // retry a link that failed earlier
                            }
                            $this->messages->send($booking, 'reminder', (string) $r->kind);
                            $n++;
                        }
                    }

                    return $n;
                });
            }
            DB::table('booking_reminders')->whereIn('booking_id', $due->pluck('booking_id'))->where('due_at', '<=', Instant::db(now()))->delete();
        }

        return $sent;
    }
}
