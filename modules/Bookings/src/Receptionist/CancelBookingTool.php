<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Platform\Contracts\ReceptionistTool;

/** Cancel a booking made in this conversation, under the business's cancellation rules. */
final readonly class CancelBookingTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private CancellationPolicy $policy) {}

    public function name(): string
    {
        return 'cancel_booking';
    }

    public function description(): string
    {
        return 'Cancel a booking made in this conversation, or found with confirm_booking_code, after the customer confirms. Tell them the cancellation rules first (policy).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'booking_id' => ['type' => 'string'],
            'confirmed' => ['type' => 'boolean', 'description' => 'True only after the customer said yes to cancelling'],
        ], 'required' => ['booking_id', 'confirmed']];
    }

    public function capability(): string
    {
        return 'change';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function run(array $arguments, array $context): array
    {
        $booking = MoveBookingTool::mine($this->chat, (string) ($arguments['booking_id'] ?? ''), $context['conversation']);
        if (($arguments['confirmed'] ?? false) !== true) {
            return ['cancelled' => false, 'policy' => $this->policy->wording(), 'deposit_refunded_if_cancelled_now' => $this->policy->refundsNow($booking)];
        }
        $refunded = $this->policy->customerCancels($booking)['refunded'];

        return ['cancelled' => true, 'deposit_refunded' => $refunded];
    }
}
