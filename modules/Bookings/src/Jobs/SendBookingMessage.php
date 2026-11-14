<?php

declare(strict_types=1);

namespace Wurk\Bookings\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Platform\Queues;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * A booking message, sent in the background on the critical queue (Sprint 18.3a): the customer's booking page answers
 * at once instead of waiting for an SMS or email provider, and no campaign can get in its way.
 */
final class SendBookingMessage implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** @param class-string<Booking|Reservation|ClassAttendee> $type */
    public function __construct(public string $tenantId, public string $type, public string $id, public string $kind, public ?string $occurrence = null)
    {
        $this->onQueue(Queues::CRITICAL);
    }

    public function handle(TenantDirectory $tenants, TenantContext $context): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null || ! in_array($this->type, [Booking::class, Reservation::class, ClassAttendee::class], true)) {
            return;
        }
        $context->run($tenant, function (): void {
            $booking = $this->type::query()->find($this->id);
            if ($booking !== null) {
                app(BookingMessages::class)->deliver($booking, $this->kind, $this->occurrence);
            }
        });
    }
}
