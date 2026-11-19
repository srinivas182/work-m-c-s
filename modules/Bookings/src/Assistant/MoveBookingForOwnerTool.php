<?php

declare(strict_types=1);

namespace Wurk\Bookings\Assistant;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Ask WURK (26.1): "move my 3 o'clock to 4", "move Thandi's braids to Monday 10:00". Finds one upcoming confirmed
 * booking by its time and/or customer, and moves it as the calendar does (nothing changes if the new time clashes).
 * Undo moves it back, if that time is still free.
 */
final readonly class MoveBookingForOwnerTool implements AssistantActionTool
{
    public function __construct(private TenantContext $context, private Booker $booker) {}

    public function name(): string
    {
        return 'move_booking';
    }

    public function description(): string
    {
        return 'Move an upcoming booking to another time. Identify it by its current date and time and/or the customer’s name.';
    }

    public function parameters(): array
    {
        return [
            'customer' => 'the customer’s name, if given',
            'date' => 'the booking’s current date, YYYY-MM-DD (today if not said)',
            'time' => 'the booking’s current time, HH:MM, if given',
            'new_date' => 'the new date, YYYY-MM-DD (the same day if not said)',
            'new_time' => 'the new time, HH:MM, 24-hour',
        ];
    }

    public function permission(): string
    {
        return 'bookings.manage';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function preview(array $params): array
    {
        [$booking, $to] = $this->check($params);
        $tz = $this->context->current()->timezone();
        $from = CarbonImmutable::parse($booking->starts_at)->setTimezone($tz);

        return ['summary' => "Move {$booking->customer_name}’s ".$this->serviceName($booking).' from '.$from->format('D j M H:i').' to '.$to->format('D j M H:i'),
            'changes' => ['Same team member and service', $booking->customer_phone ? 'They get the usual “your booking has moved” message' : 'No number on the booking, so no message is sent']];
    }

    public function apply(array $params): array
    {
        [$booking, $to] = $this->check($params);
        $was = CarbonImmutable::parse($booking->starts_at);
        try {
            $this->booker->move($booking, $to, null, (string) auth()->id());
        } catch (SlotTaken) {
            throw new AssistantRefused('That time is taken. Choose another.');
        }

        return ['done' => "Moved: {$booking->customer_name}, now ".$to->format('D j M H:i').'.', 'link' => route('tenant.bookings.calendar'),
            'undo' => ['booking_id' => $booking->id, 'starts_at' => $was->utc()->toIso8601String()]];
    }

    public function undo(array $undo): bool
    {
        $booking = Booking::query()->find((string) ($undo['booking_id'] ?? ''));
        if ($booking === null || $booking->status !== 'confirmed') {
            return false;
        }
        try {
            $this->booker->move($booking, CarbonImmutable::parse((string) $undo['starts_at']), null, (string) auth()->id());
        } catch (SlotTaken) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{Booking, CarbonImmutable}
     */
    private function check(array $params): array
    {
        $tz = $this->context->current()->timezone();
        $today = CarbonImmutable::now($tz)->format('Y-m-d');
        $date = trim((string) ($params['date'] ?? '')) ?: $today;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new AssistantRefused('Which day is the booking on?');
        }
        $day = CarbonImmutable::parse($date, $tz);
        $q = Booking::query()->where('status', 'confirmed')->where('starts_at', '>=', $day->startOfDay()->utc())->where('starts_at', '<', $day->endOfDay()->utc())
            ->where('starts_at', '>=', CarbonImmutable::now()->utc());
        $time = trim((string) ($params['time'] ?? ''));
        if ($time !== '') {
            $at = BookCustomerTool::when($date, $time, $tz);
            $q->where('starts_at', $at->utc());
        }
        $customer = mb_strtolower(trim((string) ($params['customer'] ?? '')));
        $found = $q->orderBy('starts_at')->get()->filter(fn (Booking $b) => $customer === '' || str_contains(mb_strtolower((string) $b->customer_name), $customer))->values();
        if ($found->count() !== 1) {
            throw new AssistantRefused($found->isEmpty() ? 'I can’t find an upcoming booking like that. Which day and time is it?'
                : 'There are '.$found->count().' — which one? '.$found->take(4)->map(fn (Booking $b) => $b->customer_name.' at '.CarbonImmutable::parse($b->starts_at)->setTimezone($tz)->format('H:i'))->implode(', '));
        }
        $to = BookCustomerTool::when(trim((string) ($params['new_date'] ?? '')) ?: $date, (string) ($params['new_time'] ?? ''), $tz);

        return [$found->first() ?? throw new AssistantRefused('Which booking?'), $to];
    }

    private function serviceName(Booking $b): string
    {
        return (string) (BookingService::withTrashed()->whereKey($b->service_id)->value('name') ?? 'booking');
    }
}
