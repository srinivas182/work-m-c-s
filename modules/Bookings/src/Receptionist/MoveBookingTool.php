<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;

/** Move a booking made (or found with a code) in this conversation to another free time. */
final readonly class MoveBookingTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private Booker $booker, private Availability $availability) {}

    public function name(): string
    {
        return 'move_booking';
    }

    public function description(): string
    {
        return 'Move a booking made in this conversation, or found with confirm_booking_code, to another time free_times returned, after the customer confirms.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'booking_id' => ['type' => 'string', 'description' => 'The booking_id book_appointment returned'],
            'starts_at' => ['type' => 'string', 'description' => 'The new time’s "starts_at" from free_times'],
        ], 'required' => ['booking_id', 'starts_at']];
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
        $booking = self::mine($this->chat, (string) ($arguments['booking_id'] ?? ''), $context['conversation']);
        $service = BookingService::withTrashed()->whereKey($booking->service_id)->firstOrFail();
        try {
            $to = CarbonImmutable::parse((string) ($arguments['starts_at'] ?? ''));
        } catch (\Throwable) {
            throw new ReceptionistRefused('Use a starts_at exactly as free_times returned it.');
        }
        $open = collect($this->availability->on($service, $this->chat->local($to)->format('Y-m-d'), $booking->member_id))->contains('starts_at', $to->utc()->toIso8601String());
        if (! $open) {
            throw new ReceptionistRefused('That time isn’t free. Check free_times again.');
        }
        try {
            $booking = $this->booker->move($booking, $to, null, null);
        } catch (SlotTaken) {
            throw new ReceptionistRefused('That time has just been taken. Check free_times again.');
        }

        return ['moved' => true, 'when' => $this->chat->local($booking->starts_at)->format('l j F, H:i')];
    }

    /** A booking this conversation made or verified, still ahead and active — or a refusal the AI can explain. */
    public static function mine(ChatBookings $chat, string $id, string $conversation): Booking
    {
        $booking = $chat->madeHere($conversation, 'booking', $id) ? Booking::query()->find($id) : null;
        if ($booking === null) {
            throw new ReceptionistRefused('You can only change a booking made in this chat or found with a code (find_booking). Or the customer can use the link in their confirmation message.');
        }
        if (! in_array($booking->status, Booking::ACTIVE, true) || ! $booking->starts_at->isFuture()) {
            throw new ReceptionistRefused('That booking can’t be changed any more (it’s cancelled or in the past).');
        }

        return $booking;
    }
}
