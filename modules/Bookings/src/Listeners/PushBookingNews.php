<?php

declare(strict_types=1);

namespace Wurk\Bookings\Listeners;

use Carbon\CarbonImmutable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\BookingMoved;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Platform\Contracts\PushNotifications;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Sprint 26.2: a new or moved booking buzzes the phone of the team member it's with, and of the people who manage
 * bookings — never of whoever made the change.
 */
final readonly class PushBookingNews
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private PushNotifications $push) {}

    public function handle(BookingConfirmed|BookingMoved $event): void
    {
        $b = Booking::query()->find($event->bookingId);
        if ($b === null || $b->status !== 'confirmed') {
            return;
        }
        $service = (string) (BookingService::withTrashed()->whereKey($b->service_id)->value('name') ?? '');
        $member = collect($this->team->roster())->firstWhere('id', $b->member_id);
        $at = CarbonImmutable::parse($b->starts_at)->setTimezone($this->context->current()->timezone());
        $moved = $event instanceof BookingMoved;
        $this->push->notify('booking', ($moved ? 'Booking moved: ' : 'New booking: ').$b->customer_name,
            trim($service.', '.$at->format('D j M H:i').($member !== null ? ' with '.$member['name'] : '')),
            route('tenant.bookings.calendar', ['date' => $at->toDateString()]), 'bookings.manage', $member !== null ? [$member['identity_id']] : [],
            $b->created_by !== null && ! $moved ? (string) $b->created_by : null, $b->branch_id ?? null);
    }
}
