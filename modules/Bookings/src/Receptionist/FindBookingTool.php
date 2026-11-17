<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Find a customer's upcoming bookings (spec 19.2): a 6-digit code goes to the phone or email on the booking, and
 * confirm_booking_code adds those bookings to the ones this chat may change. Knowing someone's number isn't enough.
 *
 * The answer is the same whether or not a booking exists, so the chat can't be used to find out who has booked.
 */
final readonly class FindBookingTool implements ReceptionistTool
{
    /** Codes sent per chat per hour: each SMS costs the business credits. */
    private const SENDS_PER_HOUR = 3;

    public function __construct(private TenantContext $context, private Messenger $messenger, private CreditWallet $wallet) {}

    public function name(): string
    {
        return 'find_booking';
    }

    public function description(): string
    {
        return 'When a customer wants to change or cancel a booking NOT made in this chat: send a 6-digit code to the mobile number or email on their booking. Then ask them for the code and call confirm_booking_code.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'phone' => ['type' => 'string', 'description' => 'The mobile number on the booking'],
            'email' => ['type' => 'string', 'description' => 'Or the email address on the booking'],
        ]];
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
        $phone = BookingMessages::international((string) ($arguments['phone'] ?? ''));
        $email = mb_strtolower(trim((string) ($arguments['email'] ?? '')));
        if (strlen($phone) < 10 && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ReceptionistRefused('Ask for the mobile number or email address on the booking.');
        }
        $limit = 'receptionist:find:'.$this->context->id().':'.$context['conversation'];
        if (RateLimiter::tooManyAttempts($limit, self::SENDS_PER_HOUR)) {
            throw new ReceptionistRefused('Several codes have been sent already. Ask the customer to use the link in their confirmation message, or hand over to a person.');
        }
        RateLimiter::hit($limit, 3600);

        $bookings = self::upcoming()->filter(fn (Booking $b) => $email !== ''
            ? mb_strtolower((string) $b->customer_email) === $email
            : BookingMessages::international((string) $b->customer_phone) === $phone);
        $sent = $bookings->isNotEmpty() && $this->sendCode($bookings, $phone, $email, $context['conversation']);

        return ['code_sent_if_booked' => true, 'say' => $sent || $bookings->isEmpty()
            ? 'If there’s an upcoming booking with those details, a 6-digit code has just been sent to it. Ask the customer for the code.'
            : 'A code couldn’t be sent just now. Ask the customer to use the link in their confirmation message, or hand over to a person.'];
    }

    /** @return Collection<int, Booking> */
    public static function upcoming(): Collection
    {
        return Booking::query()->whereIn('status', Booking::ACTIVE)->where('starts_at', '>', now())->orderBy('starts_at')->limit(2000)->get();
    }

    /** @param Collection<int, Booking> $bookings */
    private function sendCode(Collection $bookings, string $phone, string $email, string $conversation): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $business = $this->context->current()->displayName();
        $text = "Your {$business} code is {$code}. It's for changing your booking in our website chat and expires in 10 minutes. Don't share it with anyone else.";

        $to = $email !== '' ? $email : (string) $bookings->first(fn (Booking $b) => filter_var($b->customer_email, FILTER_VALIDATE_EMAIL) !== false)?->customer_email;
        $sent = false;
        if ($email === '' && $this->messenger->channelActive('sms')) {
            $charge = "booking-code:{$conversation}:".now()->timestamp;
            try {
                $this->wallet->charge('sms', $charge, 'Booking code (SMS) for the AI receptionist');
                $sent = $this->messenger->sms($phone, $text, 'booking')->ok;
                if (! $sent) {
                    $this->wallet->refund($charge, 'Message not delivered');
                }
            } catch (InsufficientCredits) {
                // falls back to the booking's email
            }
        }
        if (! $sent && $to !== '') {
            Mail::to($to)->send(new BookingMessageMail("Your {$business} code", $text, null));
            $sent = true;
        }
        if ($sent) {
            Cache::put(ConfirmBookingCodeTool::key($this->context->id(), $conversation), [
                'hash' => hash_hmac('sha256', $code, (string) config('app.key')), 'bookings' => $bookings->pluck('id')->all(), 'tries' => 0,
            ], now()->addMinutes(10));
        }

        return $sent;
    }
}
