<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Takes table reservations without overbooking: the evening's row is locked, capacity re-checked
 * inside the lock, then the reservation saved — a second request for that evening waits its turn.
 */
final readonly class Reserver
{
    public function __construct(private TenantContext $context, private TableAvailability $availability, private AuditLog $audit) {}

    /** @param  array{name: string, phone?: string|null, email?: string|null, notes?: string|null}  $customer */
    public function reserve(CarbonImmutable $startsAt, int $party, array $customer, string $source = 'owner', bool $asOwner = false): Reservation
    {
        $settings = ReservationSettings::query()->find($this->context->id());
        if ($settings === null || ! $settings->enabled) {
            throw new TableFull;
        }
        $tz = $this->context->current()->timezone();
        $date = $startsAt->setTimezone($tz)->format('Y-m-d');
        // Customers must pick an offered time; owners may seat anyone if there's room.
        if (! $asOwner && ! collect($this->availability->on($date, $party))->contains('starts_at', $startsAt->utc()->toIso8601String())) {
            throw new TableFull;
        }
        $until = $startsAt->addMinutes($settings->sitting_minutes);
        $db = DB::connection($this->context->connectionName());

        $reservation = $db->transaction(function () use ($db, $date, $startsAt, $until, $party, $customer, $source, $settings): Reservation {
            $db->table('reservation_days')->insertOrIgnore(['tenant_id' => $this->context->id(), 'service_date' => $date]);
            $db->table('reservation_days')->where('tenant_id', $this->context->id())->where('service_date', $date)->lockForUpdate()->first();
            if (TableAvailability::peak($this->availability->active($date), $startsAt, $until) + $party > $settings->covers) {
                throw new TableFull;
            }

            return Reservation::query()->create([
                'party_size' => $party, 'service_date' => $date, 'starts_at' => Instant::db($startsAt), 'ends_at' => Instant::db($until),
                'status' => 'confirmed', 'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'] ?? null,
                'customer_email' => $customer['email'] ?? null, 'notes' => $customer['notes'] ?? null, 'source' => $source,
            ]);
        });
        $this->audit->record('bookings.table_reserved', 'reservation', $reservation->id, ['party' => $party, 'source' => $source]);

        return $reservation;
    }

    /** seated | no_show | cancelled (cancelled frees the seats straight away). */
    public function setStatus(Reservation $reservation, string $status, ?string $by): void
    {
        $reservation->update(['status' => $status]);
        $this->audit->record('bookings.table_'.$status, 'reservation', $reservation->id, ['by' => $by]);
    }
}
