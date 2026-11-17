<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * Book an appointment at a free time, exactly as the booking form on the website does (same availability, deposit
 * and confirmation messages). A deposit means a payment link instead of a confirmed booking.
 */
final readonly class BookAppointmentTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private Booker $booker, private Deposits $deposits, private TenantContext $context) {}

    public function name(): string
    {
        return 'book_appointment';
    }

    public function description(): string
    {
        return 'Book an appointment at a time free_times returned. Only after the customer clearly said yes to the service, day, time and price, and agreed to be contacted about the booking. Needs their name and mobile number (and email when there’s a deposit).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'service' => ['type' => 'string', 'description' => 'The service’s id or name'],
            'starts_at' => ['type' => 'string', 'description' => 'The "starts_at" of the chosen free time, exactly as free_times returned it'],
            ...ChatBookings::contactProperties(),
            'online' => ['type' => 'boolean', 'description' => 'True for an online (video) appointment, when the service offers it'],
            'notes' => ['type' => 'string'],
        ], 'required' => ['service', 'starts_at', 'name', 'phone', 'agreed_to_be_contacted']];
    }

    public function capability(): string
    {
        return 'book';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function run(array $arguments, array $context): array
    {
        $service = $this->chat->service((string) ($arguments['service'] ?? ''));
        $customer = $this->chat->customer($arguments);
        try {
            $startsAt = CarbonImmutable::parse((string) ($arguments['starts_at'] ?? ''));
        } catch (\Throwable) {
            throw new ReceptionistRefused('Use a starts_at exactly as free_times returned it.');
        }
        $online = $service->location_type === 'online' || ($service->location_type === 'either' && ($arguments['online'] ?? false) === true);
        if ($online && app(HealthPractice::class)->on()) {
            throw new ReceptionistRefused('Online consultations need the practice’s consent terms: send them to the booking form on the website to book online.');
        }
        $deposit = $this->deposits->amountFor($service, $online);
        if ($deposit > 0 && $customer['email'] === null) {
            throw new ReceptionistRefused('This service needs a deposit and the receipt is emailed: ask for their email.');
        }
        try {
            $booking = $this->booker->book($service, $startsAt, [...$customer, 'notes' => mb_substr((string) ($arguments['notes'] ?? ''), 0, 1000) ?: null],
                null, source: 'chat', status: $deposit > 0 ? 'pending_payment' : 'confirmed', online: $online);
        } catch (SlotTaken) {
            throw new ReceptionistRefused('That time has just been taken. Check free_times again and offer the nearest ones.');
        }
        $this->chat->remember($context['conversation'], 'booking', $booking->id);
        $when = $this->chat->local($booking->starts_at)->format('l j F, H:i');
        $card = ['type' => 'booking', 'service' => $service->name, 'when' => $when, 'price' => $this->chat->money($service->price_cents)];
        if ($deposit > 0) {
            $pay = $this->deposits->start($booking, $deposit, (string) $customer['email'], $this->chat->returnUrl());
            if ($pay === null) {
                $this->booker->cancel($booking, null, 'expired');
                throw new ReceptionistRefused('Online payment isn’t available right now, so this can’t be booked here. Offer to pass it to a person.');
            }

            return ['booked' => false, 'awaiting_deposit' => true, 'booking_id' => $booking->id, 'when' => $when, 'deposit' => $this->chat->money($deposit), 'pay_url' => $pay,
                'tell_the_customer' => 'The time is held while they pay the deposit with the link; it’s confirmed once paid.',
                'card' => [...$card, 'deposit' => $this->chat->money($deposit), 'action' => ['label' => 'Pay the deposit', 'url' => $pay]]];
        }
        $manage = ManageLink::for($booking, $this->context->current()->handle());

        return ['booked' => true, 'booking_id' => $booking->id, 'service' => $service->name, 'when' => $when, 'price' => $this->chat->money($service->price_cents),
            'tell_the_customer' => 'Booked. A confirmation is on its way, with a link to change or cancel.',
            'card' => [...$card, 'action' => ['label' => 'Change or cancel', 'url' => $manage]]];
    }
}
