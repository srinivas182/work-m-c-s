<?php

declare(strict_types=1);

namespace Wurk\Bookings\Listeners;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Events\ClassJoined;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Support\BookingMessages;
use Wurk\Messaging\Contracts\WhatsAppBusiness;
use Wurk\Messaging\Events\WhatsAppSent;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Sprint 21.5: when the deposit for a booking made on WhatsApp is paid (by the link the assistant sent), the chat
 * confirms it from the business's own number, straight away.
 */
final readonly class ConfirmPaymentOnWhatsApp
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private WhatsAppBusiness $whatsapp) {}

    public function handle(BookingConfirmed|ClassJoined $event): void
    {
        $tenant = $this->tenants->find($event->tenantId);
        if ($tenant === null) {
            return;
        }
        $this->context->run($tenant, function () use ($event): void {
            $booking = $event instanceof ClassJoined ? ClassAttendee::query()->find($event->attendeeId) : Booking::query()->find($event->bookingId);
            // Only a deposit just paid, on a booking made in a WhatsApp chat.
            if ($booking === null || $booking->source !== 'whatsapp' || $booking->paid_at === null || $booking->paid_at->lt(now()->subMinutes(30)) || ! $this->whatsapp->connected()) {
                return;
            }
            $to = BookingMessages::international((string) $booking->customer_phone);
            $what = $booking instanceof ClassAttendee ? $booking->session()->title : (string) BookingService::withTrashed()->whereKey($booking->service_id)->value('name');
            $start = $booking instanceof ClassAttendee ? $booking->session()->starts_at : $booking->starts_at;
            $paid = 'R'.number_format(($booking->deposit_cents - (int) $booking->wallet_cents) / 100, 2, '.', ' ');
            $text = 'Payment received — thank you, '.strtok($booking->customer_name, ' ').'! Your '.$paid.' '.($booking instanceof ClassAttendee ? 'payment' : 'deposit')
                .' is in, and your '.$what.' on '.CarbonImmutable::instance($start)->setTimezone($this->context->current()->timezone())->format('l j F \a\t H:i').' is confirmed.';
            $sent = $this->whatsapp->sendText($to, $text);
            if ($sent->ok) {
                event(new WhatsAppSent($this->context->id(), ltrim($to, '+'), $text, $sent->providerId));
            }
        });
    }
}
