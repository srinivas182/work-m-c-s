<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Events\BookingCancelled;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Payments\Contracts\Coupons;
use Wurk\Platform\Contracts\CannotRestore;
use Wurk\Platform\Contracts\RecentlyDeleted;
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
    public function book(BookingService $service, CarbonImmutable $startsAt, array $customer, ?string $memberId = null, string $source = 'owner', ?string $by = null, string $status = 'confirmed', bool $asOwner = false, bool $online = false, ?string $branchId = null): Booking
    {
        $branch = app(BranchServices::class)->resolve($branchId); // an open branch, or the main one
        $startsAt = $startsAt->utc();
        // Owners (walk-ins, squeezing someone in) aren't held to notice, horizon or listed hours —
        // only to the no-overlap guarantee, which the slots enforce for everyone.
        if ($asOwner) {
            $members = $memberId !== null ? [$memberId] : array_values(DB::connection($this->context->connectionName())->table('booking_service_member')
                ->where('service_id', $service->id)->pluck('member_id')->map(fn ($m) => (string) $m)->values()->all());

            return $this->claim($service, $startsAt, $customer, $members, $source, $by, $status, $online, branch: $branch);
        }
        // Open for this service at this time (hours, time off, notice) — then the database decides the race.
        $date = $startsAt->setTimezone($this->context->current()->timezone())->format('Y-m-d');
        $open = collect($this->availability->on($service, $date, $memberId, null, $branch))->firstWhere('starts_at', $startsAt->toIso8601String());
        if ($open === null) {
            throw new SlotTaken;
        }

        return $this->claim($service, $startsAt, $customer, $open['members'], $source, $by, $status, $online, branch: $branch);
    }

    /**
     * Save the booking and claim its slots, trying each candidate team member in turn.
     *
     * @param  array{name: string, phone?: string|null, email?: string|null, notes?: string|null}  $customer
     * @param  list<string>  $members
     */
    private function claim(BookingService $service, CarbonImmutable $startsAt, array $customer, array $members, string $source, ?string $by, string $status, bool $online = false, ?string $branch = null): Booking
    {
        $from = $startsAt->subMinutes($service->buffer_before_minutes);
        $until = $startsAt->addMinutes($service->duration_minutes + $service->buffer_after_minutes);

        foreach ($members as $member) { // "anyone": the first team member free at that time
            try {
                $booking = DB::connection($this->context->connectionName())->transaction(function () use ($service, $startsAt, $customer, $member, $source, $by, $status, $from, $until, $online, $branch): Booking {
                    $booking = Booking::query()->create([
                        'service_id' => $service->id, 'member_id' => $member, 'starts_at' => Instant::db($startsAt),
                        'ends_at' => Instant::db($startsAt->addMinutes($service->duration_minutes)), 'status' => $status, 'online' => $online,
                        'customer_name' => $customer['name'], 'customer_phone' => $customer['phone'] ?? null,
                        'customer_email' => $customer['email'] ?? null, 'notes' => $customer['notes'] ?? null,
                        'source' => $source, 'created_by' => $by, 'branch_id' => $branch,
                    ]);
                    DB::connection($this->context->connectionName())->table('booking_slots')->insert(array_map(fn (CarbonImmutable $slot) => [
                        'member_id' => $member, 'slot_at' => Instant::db($slot), 'booking_id' => $booking->id, 'tenant_id' => $this->context->id(),
                    ], Availability::slots($from, $until)));

                    return $booking;
                });
                $this->audit->record('bookings.booked', 'booking', $booking->id, ['service' => $service->name, 'starts_at' => $startsAt->toIso8601String(), 'source' => $source]);
                if ($status === 'confirmed') {
                    event(new BookingConfirmed($this->context->id(), $booking->id));
                }

                return $booking;
            } catch (UniqueConstraintViolationException) {
                continue; // taken a moment ago: try the next free team member, if any
            }
        }

        throw new SlotTaken;
    }

    /**
     * Move a booking (time and/or team member) in one transaction: release its slots, claim the new
     * ones, update it. If the new time clashes, nothing changes — it keeps its original time.
     */
    public function move(Booking $booking, CarbonImmutable $startsAt, ?string $memberId, ?string $by): Booking
    {
        $service = BookingService::withTrashed()->whereKey($booking->service_id)->firstOrFail(); // still movable if its service was deleted since
        $member = $memberId ?? $booking->member_id;
        $startsAt = $startsAt->utc();
        try {
            DB::connection($this->context->connectionName())->transaction(function () use ($booking, $service, $member, $startsAt): void {
                $db = DB::connection($this->context->connectionName());
                $db->table('booking_slots')->where('booking_id', $booking->id)->delete();
                $db->table('booking_slots')->insert(array_map(fn (CarbonImmutable $slot) => [
                    'member_id' => $member, 'slot_at' => Instant::db($slot), 'booking_id' => $booking->id, 'tenant_id' => $this->context->id(),
                ], Availability::slots($startsAt->subMinutes($service->buffer_before_minutes), $startsAt->addMinutes($service->duration_minutes + $service->buffer_after_minutes))));
                $booking->update(['member_id' => $member, 'starts_at' => Instant::db($startsAt), 'ends_at' => Instant::db($startsAt->addMinutes($service->duration_minutes))]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new SlotTaken;
        }
        $this->audit->record('bookings.moved', 'booking', $booking->id, ['to' => $startsAt->toIso8601String(), 'by' => $by]);
        event(new BookingMoved($this->context->id(), $booking->id));

        return $booking->refresh();
    }

    /** Cancelling frees the time straight away. */
    public function cancel(Booking $booking, ?string $by, string $status = 'cancelled'): void
    {
        app(Coupons::class)->release('booking', $booking->id); // the booking won't stand: any coupon use goes back (15.3b)
        DB::connection($this->context->connectionName())->transaction(function () use ($booking, $status): void {
            $booking->update(['status' => $status, 'cancelled_at' => $status === 'cancelled' ? now() : null]);
            DB::connection($this->context->connectionName())->table('booking_slots')->where('booking_id', $booking->id)->delete();
        });
        $this->audit->record('bookings.'.$status, 'booking', $booking->id, ['by' => $by]);
        if ($status === 'cancelled') { // not for expired holds or no-shows
            event(new BookingCancelled($this->context->id(), $booking->id));
        }
    }

    /**
     * Put a cancelled booking back (Sprint 16.6c): within 30 days, while it's still ahead, if its time is still free
     * for the same person. Not for paid deposits (refunded when cancelled) or online meetings (their link was removed).
     * The customer is told, as for a new booking.
     */
    public function restore(Booking $booking, ?string $by): Booking
    {
        if ($booking->status !== 'cancelled' || $booking->cancelled_at === null || $booking->cancelled_at->lt(now()->subDays(RecentlyDeleted::DAYS))) {
            throw new CannotRestore('That booking isn’t in Recently deleted any more.');
        }
        if ($booking->starts_at->isPast()) {
            throw new CannotRestore('That booking’s time has passed.');
        }
        if ($booking->deposit_cents > 0 || $booking->online) {
            throw new CannotRestore($booking->online ? 'Online bookings can’t be put back — book it again so a new meeting link is sent.' : 'A booking with a deposit can’t be put back — book it again so the deposit is taken.');
        }
        $service = BookingService::withTrashed()->whereKey($booking->service_id)->firstOrFail();
        $start = CarbonImmutable::instance($booking->starts_at)->utc();
        try {
            DB::connection($this->context->connectionName())->transaction(function () use ($booking, $service, $start): void {
                DB::connection($this->context->connectionName())->table('booking_slots')->insert(array_map(fn (CarbonImmutable $slot) => [
                    'member_id' => $booking->member_id, 'slot_at' => Instant::db($slot), 'booking_id' => $booking->id, 'tenant_id' => $this->context->id(),
                ], Availability::slots($start->subMinutes($service->buffer_before_minutes), $start->addMinutes($service->duration_minutes + $service->buffer_after_minutes))));
                $booking->update(['status' => 'confirmed', 'cancelled_at' => null]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new CannotRestore('That time has been booked since — book it again at another time.');
        }
        $this->audit->record('bookings.restored', 'booking', $booking->id, ['by' => $by]);
        event(new BookingConfirmed($this->context->id(), $booking->id));

        return $booking->refresh();
    }
}
