<?php

declare(strict_types=1);

namespace Wurk\Bookings\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Contracts\InsufficientCredits;
use Wurk\Credits\Contracts\SpendingLimitReached;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Sends a booking message once, on the owner's channel. SMS and WhatsApp are charged in WURK Credits
 * first; a failed send is refunded. If credits run out or a send fails, the message falls back to
 * free email — essential messages never silently stop — and the outcome is recorded.
 */
final readonly class BookingMessages
{
    public function __construct(private TenantContext $context, private Messenger $messenger, private CreditWallet $wallet) {}

    /** @param  string  $kind  confirmed | reminder | moved | cancelled; $occurrence makes repeats distinct (e.g. reminder_24h, moved_2) */
    public function send(Booking $booking, string $kind, ?string $occurrence = null): void
    {
        $key = $occurrence ?? $kind;
        $db = DB::connection($this->context->connectionName());
        $id = (string) Str::ulid();
        if ($db->table('booking_messages')->insertOrIgnore(['id' => $id, 'tenant_id' => $this->context->id(), 'booking_id' => $booking->id, 'kind' => $key, 'status' => 'sending', 'created_at' => now()]) === 0) {
            return; // already sent (or being sent): each message once
        }
        $settings = BookingSettings::query()->find($this->context->id());
        $channel = $settings === null ? 'email' : $settings->message_channel;
        $vars = $this->vars($booking, $kind);
        $phone = self::international((string) $booking->customer_phone);

        $outcome = ['channel' => null, 'status' => 'failed', 'detail' => null];
        if (in_array($channel, ['sms', 'whatsapp'], true) && $phone !== '' && $this->messenger->channelActive($channel)) {
            $charge = "booking-message:{$booking->id}:{$key}";
            try {
                $this->wallet->charge($channel, $charge, "Booking {$kind} ({$channel})");
                $result = $channel === 'sms'
                    ? $this->messenger->sms($phone, MessageWording::text($kind, $vars), 'booking')
                    : $this->messenger->whatsappTemplate($phone, 'wurk_booking_'.$kind, MessageWording::whatsappParameters($kind, $vars), 'booking');
                if ($result->ok) {
                    $outcome = ['channel' => $channel, 'status' => 'sent', 'detail' => null];
                } else {
                    $this->wallet->refund($charge, 'Message not delivered');
                    $outcome['detail'] = "Fell back to email: the {$channel} didn’t send";
                }
            } catch (InsufficientCredits $e) {
                $outcome['detail'] = 'Fell back to email: '.($e instanceof SpendingLimitReached ? 'spending limit reached' : 'not enough credits');
            }
        } elseif ($channel !== 'email') {
            $outcome['detail'] = $phone === '' ? 'Fell back to email: no phone number' : "Fell back to email: {$channel} isn’t available";
        }

        if ($outcome['status'] !== 'sent') {
            if (filter_var($booking->customer_email, FILTER_VALIDATE_EMAIL) !== false) {
                try {
                    Mail::to((string) $booking->customer_email)->send(new BookingMessageMail(MessageWording::subject($kind, $vars), MessageWording::text($kind, $vars), $vars['link'] ?: null));
                    $outcome = ['channel' => 'email', 'status' => 'sent', 'detail' => $outcome['detail']];
                } catch (Throwable) {
                    $outcome['detail'] = trim(($outcome['detail'] ?? '').' Email didn’t send.');
                }
            } else {
                $outcome['detail'] = trim(($outcome['detail'] ? $outcome['detail'].'; ' : '').'no email address to fall back to');
            }
        }
        $db->table('booking_messages')->where('id', $id)->update($outcome);
    }

    /** @return array{client: string, service: string, business: string, when: string, link: string} */
    private function vars(Booking $booking, string $kind): array
    {
        $tenant = $this->context->current();

        return [
            'client' => (string) strtok($booking->customer_name, ' '),
            'service' => (string) BookingService::query()->whereKey($booking->service_id)->value('name'),
            'business' => $tenant->displayName(),
            'when' => $booking->starts_at->setTimezone($tenant->timezone())->format('D j M, H:i'),
            'link' => $kind === 'cancelled' || $booking->starts_at->isPast() ? '' : ManageLink::for($booking, $tenant->handle()),
        ];
    }

    /** South African numbers to international form (0… → +27…), as the Contact section does. */
    public static function international(string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return '';
        }

        return '+'.(str_starts_with($digits, '0') ? '27'.substr($digits, 1) : $digits);
    }
}
