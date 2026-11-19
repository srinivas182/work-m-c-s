<?php

declare(strict_types=1);

namespace Wurk\Bookings\Assistant;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Platform\Contracts\AssistantActionTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Ask WURK (26.1, e.g. on WhatsApp): "book Thandi for braids Saturday 10am". Booked as the owner books from the
 * calendar — not held to notice or listed hours, only to nobody being double-booked. Undo cancels it.
 */
final readonly class BookCustomerTool implements AssistantActionTool
{
    public function __construct(private TenantContext $context, private Booker $booker, private TeamDirectory $team) {}

    public function name(): string
    {
        return 'book_customer';
    }

    public function description(): string
    {
        return 'Book a customer in for a service at a date and time, as the owner does from the calendar.';
    }

    public function parameters(): array
    {
        return [
            'customer' => 'the customer’s name',
            'phone' => 'their mobile number, if given',
            'service' => 'the service, as the business calls it',
            'date' => 'YYYY-MM-DD',
            'time' => 'HH:MM, 24-hour',
            'member' => 'the team member, if one was named',
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
        $p = $this->check($params);

        return ['summary' => "Book {$p['customer']} for {$p['service']->name}, ".$p['at']->format('l j F').' at '.$p['at']->format('H:i'),
            'changes' => ["{$p['service']->name} ({$p['service']->duration_minutes} minutes) with ".($p['member'] ?? 'whoever is free'),
                $p['phone'] !== null ? "Their number: {$p['phone']} — they get the usual confirmation" : 'No number given, so no confirmation is sent'],
        ];
    }

    public function apply(array $params): array
    {
        $p = $this->check($params);
        try {
            $booking = $this->booker->book($p['service'], $p['at'], ['name' => $p['customer'], 'phone' => $p['phone']], $p['member_id'], 'owner', (string) auth()->id(), asOwner: true);
        } catch (SlotTaken) {
            throw new AssistantRefused('That time was just taken. Choose another time.');
        }

        return ['done' => "Booked: {$p['customer']}, {$p['service']->name}, ".$p['at']->format('D j M H:i').'.',
            'link' => route('tenant.bookings.calendar'), 'undo' => ['booking_id' => $booking->id]];
    }

    public function undo(array $undo): bool
    {
        $booking = Booking::query()->find((string) ($undo['booking_id'] ?? ''));
        if ($booking === null || $booking->status !== 'confirmed' || $booking->starts_at->isPast()) {
            return false;
        }
        $this->booker->cancel($booking, (string) auth()->id());

        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{service: BookingService, at: CarbonImmutable, customer: string, phone: string|null, member_id: string|null, member: string|null}
     */
    private function check(array $params): array
    {
        $customer = trim(strip_tags((string) ($params['customer'] ?? '')));
        if ($customer === '') {
            throw new AssistantRefused('Who is the booking for?');
        }
        $service = self::service((string) ($params['service'] ?? ''));
        $at = self::when((string) ($params['date'] ?? ''), (string) ($params['time'] ?? ''), $this->context->current()->timezone());
        $digits = preg_replace('/[^\d+]/', '', (string) ($params['phone'] ?? '')) ?? '';
        $phone = strlen(ltrim($digits, '+')) >= 9 ? $digits : null;
        [$memberId, $member] = [null, null];
        $offering = DB::connection($this->context->connectionName())->table('booking_service_member')->where('tenant_id', $this->context->id())->where('service_id', $service->id)->pluck('member_id')->map(fn ($m) => (string) $m)->all();
        if (trim((string) ($params['member'] ?? '')) !== '') {
            $name = mb_strtolower(trim((string) $params['member']));
            $found = array_values(array_filter($this->team->members(), fn (array $m) => str_starts_with(mb_strtolower($m['name']), $name)));
            if (count($found) !== 1) {
                throw new AssistantRefused($found === [] ? "I can’t find a team member called {$params['member']}." : 'Which one — '.implode(' or ', array_column($found, 'name')).'?');
            }
            if (! in_array($found[0]['id'], $offering, true)) {
                throw new AssistantRefused("{$found[0]['name']} doesn’t do {$service->name}.");
            }
            [$memberId, $member] = [$found[0]['id'], $found[0]['name']];
        }
        // Nobody is double-booked: someone who does this service must be free then (buffers included).
        $slots = Availability::slots($at->utc()->subMinutes($service->buffer_before_minutes), $at->utc()->addMinutes($service->duration_minutes + $service->buffer_after_minutes));
        $busy = DB::connection($this->context->connectionName())->table('booking_slots')->where('tenant_id', $this->context->id())->whereIn('member_id', $memberId !== null ? [$memberId] : $offering)
            ->whereIn('slot_at', array_map(fn (CarbonImmutable $s) => Instant::db($s), $slots))->distinct()->pluck('member_id')->map(fn ($m) => (string) $m)->all();
        if (array_diff($memberId !== null ? [$memberId] : $offering, $busy) === []) {
            throw new AssistantRefused(($member ?? 'Everyone who does '.$service->name).' is booked then. Try another time.');
        }

        return ['service' => $service, 'at' => $at, 'customer' => mb_substr($customer, 0, 120), 'phone' => $phone, 'member_id' => $memberId, 'member' => $member];
    }

    /** A service by its name ("braids" finds "Knotless braids"). */
    public static function service(string $name): BookingService
    {
        $needle = mb_strtolower(trim($name));
        $all = BookingService::query()->where('active', true)->get();
        $exact = $all->filter(fn (BookingService $s) => mb_strtolower($s->name) === $needle);
        $found = $exact->isNotEmpty() ? $exact : $all->filter(fn (BookingService $s) => $needle !== '' && str_contains(mb_strtolower($s->name), $needle));
        if ($found->count() === 1 && ($one = $found->first()) !== null) {
            return $one;
        }
        throw new AssistantRefused($found->isEmpty() ? ($needle === '' ? 'Which service?' : "I can’t find a service called {$name}.")
            : 'Which one — '.$found->pluck('name')->implode(' or ').'?');
    }

    /** A date and time in the business's time zone, not in the past. */
    public static function when(string $date, string $time, string $timezone): CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($date)) !== 1 || preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $t) !== 1 || (int) $t[1] > 23 || (int) $t[2] > 59) {
            throw new AssistantRefused('Which day and time?');
        }
        try {
            $at = CarbonImmutable::parse(trim($date).' '.sprintf('%02d:%02d', (int) $t[1], (int) $t[2]), $timezone);
        } catch (\Throwable) {
            throw new AssistantRefused('Which day and time?');
        }
        if ($at->lt(CarbonImmutable::now($timezone)->subMinutes(5))) {
            throw new AssistantRefused('That time has passed. Which day did you mean?');
        }

        return $at;
    }
}
