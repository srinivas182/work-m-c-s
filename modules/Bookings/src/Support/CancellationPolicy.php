<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Contracts\SplitPayments;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * The business's cancellation policy. A customer cancelling at least `cancellation_hours` before
 * the booking gets the deposit back; later cancellations and no-shows keep it. When the business
 * cancels, the deposit is always refunded.
 */
final readonly class CancellationPolicy
{
    public const DEFAULT_HOURS = 24;

    public function __construct(private TenantContext $context, private SplitPayments $payments, private Booker $booker, private AuditLog $audit) {}

    public function hours(): int
    {
        $settings = BookingSettings::query()->find($this->context->id());

        return $settings === null ? self::DEFAULT_HOURS : $settings->cancellation_hours;
    }

    /** The policy in words (the owner's own, or the default with their window). */
    public function wording(): string
    {
        $own = trim((string) BookingSettings::query()->find($this->context->id())?->policy);

        return $own !== '' ? $own : self::defaultWording($this->hours());
    }

    public static function defaultWording(int $hours): string
    {
        return "Cancel or change your booking at least {$hours} hours before it starts and any deposit is refunded. Later cancellations and missed bookings keep the deposit.";
    }

    /** Would cancelling now refund the deposit? */
    public function refundsNow(Booking|ClassAttendee $booking): bool
    {
        $start = $booking instanceof ClassAttendee ? $booking->session()->starts_at : $booking->starts_at;

        return $booking->paid_at !== null && $booking->refunded_at === null && now()->lessThanOrEqualTo($start->subHours($this->hours()));
    }

    /** @return array{refunded: bool} */
    public function customerCancels(Booking $booking): array
    {
        $refunded = $this->refundsNow($booking) && $this->refund($booking);
        $this->booker->cancel($booking, null);

        return ['refunded' => $refunded];
    }

    /**
     * The business cancelled: any deposit paid goes back.
     *
     * @return array{refunded: bool}
     */
    public function businessCancels(Booking $booking, ?string $by): array
    {
        $refunded = $booking->paid_at !== null && $booking->refunded_at === null && $this->refund($booking);
        $this->booker->cancel($booking, $by);

        return ['refunded' => $refunded];
    }

    /** Refund a paid deposit or class place; true if Paystack accepted it. */
    public function refund(Booking|ClassAttendee $booking): bool
    {
        if ($booking->payment_reference === null || ! $this->payments->refund($booking->payment_reference)) {
            $this->audit->record('bookings.refund_failed', $booking instanceof ClassAttendee ? 'class_attendee' : 'booking', $booking->id, []);

            return false;
        }
        $booking->update(['refunded_at' => Instant::db(now())]);
        $this->audit->record('bookings.deposit_refunded', $booking instanceof ClassAttendee ? 'class_attendee' : 'booking', $booking->id, ['cents' => $booking->deposit_cents]);

        return true;
    }
}
