<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Makes and cancels bookings. A booking claims its 5-minute slots (buffers included) in the same
 * transaction; the unique key on (team member, slot) means two overlapping bookings can never both
 * be saved — the second gets SlotTaken, even if both arrive at once.
 */
final readonly class Booker
{
    public function __construct(private TenantContext $context, private Availability $availability, private AuditLog $audit) {}

    /**
     * @param  array{name: string, phone?: string|null, email?: string|null, notes?: string|null}  $customer
     */
    public function book(BookingService $service, CarbonImmutable $startsAt, array $customer, ?string $memberId = null, string $source = 'owner', ?string $by = null, string $status = 'confirmed'): Booking
    {
        $startsAt = $startsAt->utc();
        // Open for this service at this time (hours, time off, notice) — then the database decides the race.
        $date = $startsAt->setTimezone($this->context->current()->timezone())->format('Y-m-d');
        $open = collect($this->availability->on($service, $date, $memberId))->firstWhere('starts_at', $startsAt->toIso8601String());
        if ($open === null) {
            throw new SlotTaken;
        }
        $from = $startsAt->subMinutes($service->buffer_before_minutes);
        $until = $startsAt->addMinutes($service->duration_minutes + $service->buffer_after_minutes);

        foreach ($open['members'] as $member) { // "anyone": the first team member free at that time
            try {
                $booking = DB::connection($this->context->connectionName())->transaction(function () use ($service, $startsAt, $customer, $member, $source, $by, $status, $from, $until): Booking {
                    $booking = Booking::query()->create([
                        'service_id' => $service->id, 'member_id' => $member, 'starts_at' => $startsAt,
                        'ends_at' => $startsAt->addMinutes($service->duration_minutes), 'status' => $status,
                        'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'] ?? null,
                        'customer_email' => $customer['email'] ?? null, 'notes' => $customer['notes'] ?? null,
                        'source' => $source, 'created_by' => $by,
                    ]);
                    DB::connection($this->context->connectionName())->table('booking_slots')->insert(array_map(fn (CarbonImmutable $slot) => [
                        'member_id' => $member, 'slot_at' => $slot->format('Y-m-d H:i:s'), 'booking_id' => $booking->id, 'tenant_id' => $this->context->id(),
                    ], Availability::slots($from, $until)));

                    return $booking;
                });
                $this->audit->record('bookings.booked', 'booking', $booking->id, ['service' => $service->name, 'starts_at' => $startsAt->toIso8601String(), 'source' => $source]);

                return $booking;
            } catch (UniqueConstraintViolationException) {
                continue; // taken a moment ago: try the next free team member, if any
            }
        }

        throw new SlotTaken;
    }

    /** Cancelling frees the time straight away. */
    public function cancel(Booking $booking, ?string $by, string $status = 'cancelled'): void
    {
        DB::connection($this->context->connectionName())->transaction(function () use ($booking, $status): void {
            $booking->update(['status' => $status]);
            DB::connection($this->context->connectionName())->table('booking_slots')->where('booking_id', $booking->id)->delete();
        });
        $this->audit->record('bookings.'.$status, 'booking', $booking->id, ['by' => $by]);
    }
}
