<?php

declare(strict_types=1);

namespace Wurk\Bookings\WhatsApp;

use Carbon\CarbonImmutable;
use Throwable;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Messaging\Contracts\WhatsAppAction;
use Wurk\Messaging\Contracts\WhatsAppAnswer;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * The Confirm / Move / Cancel buttons on a booking reminder sent from the business's own WhatsApp number (Sprint 21.5).
 * A tap is handled straight away: Confirm marks the booking in the diary, Move offers the next free times (a tap moves
 * it), and Cancel frees the slot — asking first only when the deposit wouldn't be refunded. Only the booking's own
 * customer (by WhatsApp number) can act on it.
 *
 * Payloads: booking:confirm:{id}, booking:move:{id}, booking:moveto:{id}:{unix}, booking:cancel:{id}, booking:cancelyes:{id}.
 */
final readonly class ReminderButtons implements WhatsAppAction
{
    public const PREFIX = 'booking';

    /** How far ahead Move looks, and how many times it offers (a WhatsApp list holds 10). */
    public const DAYS = 14;

    public const TIMES = 10;

    public function __construct(private TenantContext $context, private Booker $booker, private CancellationPolicy $policy, private Availability $availability) {}

    public function prefix(): string
    {
        return self::PREFIX;
    }

    /** @return list<string> the reminder's three buttons' payloads, in order */
    public static function payloads(Booking $booking): array
    {
        return [self::PREFIX.':confirm:'.$booking->id, self::PREFIX.':move:'.$booking->id, self::PREFIX.':cancel:'.$booking->id];
    }

    public function handle(array $parts, string $from): ?WhatsAppAnswer
    {
        [$action, $id] = [$parts[0] ?? '', $parts[1] ?? ''];
        if (! in_array($action, ['confirm', 'move', 'moveto', 'cancel', 'cancelyes'], true)) {
            return null;
        }
        $booking = Booking::query()->find($id);
        // Only the customer the booking is for: the number WhatsApp verified must be the booking's number.
        if ($booking === null || ltrim(BookingMessages::international((string) $booking->customer_phone), '+') !== preg_replace('/\D/', '', $from)) {
            return new WhatsAppAnswer('Sorry, I couldn’t find that booking. Reply here and someone from the team will help.');
        }
        if (! in_array($booking->status, Booking::ACTIVE, true) || ! $booking->starts_at->isFuture()) {
            return new WhatsAppAnswer('That booking has already been cancelled or has passed. Reply here if you’d like a new one.');
        }

        return match ($action) {
            'confirm' => $this->confirm($booking),
            'move' => $this->times($booking, 'Here are the next free times for your '.$this->service($booking)->name.'. Tap one to move your booking:'),
            'moveto' => $this->moveTo($booking, (int) ($parts[2] ?? 0)),
            'cancel' => $this->cancel($booking, false),
            default => $this->cancel($booking, true),
        };
    }

    private function confirm(Booking $booking): WhatsAppAnswer
    {
        $booking->update(['customer_confirmed_at' => now()]);

        return new WhatsAppAnswer('Thanks, '.$this->first($booking).'! You’re confirmed for '.$this->when($booking->starts_at).'. See you then.');
    }

    private function times(Booking $booking, string $text): WhatsAppAnswer
    {
        $service = $this->service($booking);
        $tz = $this->context->current()->timezone();
        $choices = [];
        $day = CarbonImmutable::now($tz)->startOfDay();
        for ($d = 0; $d < self::DAYS && count($choices) < self::TIMES; $d++, $day = $day->addDay()) {
            foreach ($this->availability->on($service, $day->format('Y-m-d'), $booking->member_id) as $slot) {
                $at = CarbonImmutable::parse($slot['starts_at']);
                if ($at->equalTo($booking->starts_at)) {
                    continue;
                }
                $choices[] = ['id' => self::PREFIX.':moveto:'.$booking->id.':'.$at->getTimestamp(), 'title' => $at->setTimezone($tz)->format('D j M, H:i'), 'description' => $service->name];
                if (count($choices) >= self::TIMES) {
                    break;
                }
            }
        }

        return $choices === []
            ? new WhatsAppAnswer('There are no free times in the next two weeks, sorry. Reply here and someone from the team will help you find one.')
            : new WhatsAppAnswer($text, $choices);
    }

    private function moveTo(Booking $booking, int $unix): WhatsAppAnswer
    {
        $to = CarbonImmutable::createFromTimestampUTC($unix);
        if ($to->equalTo($booking->starts_at)) { // tapped twice
            return new WhatsAppAnswer('That’s already your time: '.$this->when($booking->starts_at).'. See you then!');
        }
        $free = $unix > 0 && $to->isFuture() && collect($this->availability->on($this->service($booking), $to->setTimezone($this->context->current()->timezone())->format('Y-m-d'), $booking->member_id))
            ->contains(fn (array $s) => CarbonImmutable::parse($s['starts_at'])->equalTo($to));
        if (! $free) {
            return $this->times($booking, 'Sorry, that time has just gone. Here are the free times now:');
        }
        try {
            $booking = $this->booker->move($booking, $to, null, null);
        } catch (SlotTaken) {
            return $this->times($booking, 'Sorry, that time has just gone. Here are the free times now:');
        }

        return new WhatsAppAnswer('Done — your '.$this->service($booking)->name.' is now '.$this->when($booking->starts_at).'.');
    }

    private function cancel(Booking $booking, bool $sure): WhatsAppAnswer
    {
        $losesDeposit = $booking->paid_at !== null && $booking->deposit_cents > 0 && ! $this->policy->refundsNow($booking);
        if ($losesDeposit && ! $sure) {
            return new WhatsAppAnswer('It’s too close to your booking for the deposit to be refunded ('.$this->policy->wording().'). Cancel anyway?', [
                ['id' => self::PREFIX.':cancelyes:'.$booking->id, 'title' => 'Yes, cancel'],
                ['id' => self::PREFIX.':confirm:'.$booking->id, 'title' => 'Keep my booking'],
            ]);
        }
        try {
            $refunded = $this->policy->customerCancels($booking)['refunded'];
        } catch (Throwable $e) {
            report($e);

            return new WhatsAppAnswer('Sorry — that didn’t work just now. Reply here and someone from the team will cancel it for you.');
        }

        return new WhatsAppAnswer('Your '.$this->service($booking)->name.' on '.$this->when($booking->starts_at).' is cancelled.'
            .($refunded ? ' Your deposit is being refunded.' : '').' Reply here any time to book again.');
    }

    private function service(Booking $booking): BookingService
    {
        return BookingService::withTrashed()->whereKey($booking->service_id)->firstOrFail();
    }

    private function first(Booking $booking): string
    {
        return (string) strtok($booking->customer_name, ' ');
    }

    private function when(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone($this->context->current()->timezone())->format('l j F \a\t H:i');
    }
}
