<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Support\ClassBooker;
use Wurk\Bookings\Support\ClassFull;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;
use Wurk\Tenancy\Contracts\TenantContext;

/** Book a place in a group class, as the class timetable on the website does (no overbooking; paid places pay first). */
final readonly class JoinClassTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private ClassBooker $booker, private Deposits $deposits, private TenantContext $context) {}

    public function name(): string
    {
        return 'join_class';
    }

    public function description(): string
    {
        return 'Book a place in a class the classes tool returned, after the customer said yes to the class, time and price and agreed to be contacted. Needs their name and mobile number (and email when it’s paid).';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'class_id' => ['type' => 'string', 'description' => 'The class_id from the classes tool'],
            ...ChatBookings::contactProperties(),
        ], 'required' => ['class_id', 'name', 'phone', 'agreed_to_be_contacted']];
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
        $class = ClassSession::query()->whereKey((string) ($arguments['class_id'] ?? ''))->first()
            ?? throw new ReceptionistRefused('There is no class like that. Use the classes tool.');
        if ($class->location_type === 'online' && app(HealthPractice::class)->on()) {
            throw new ReceptionistRefused('Online sessions need the practice’s consent terms: send them to the class timetable on the website to book.');
        }
        $price = $this->deposits->amountForClass($class);
        $customer = $this->chat->customer($arguments, $price > 0, 'the class');
        try {
            $place = $this->booker->attend($class, $customer, 'chat', $price > 0 ? 'pending_payment' : 'confirmed');
        } catch (ClassFull) {
            throw new ReceptionistRefused('That class is full (or has started). Offer another one from the classes tool.');
        }
        $this->chat->remember($context['conversation'], 'class', $place->id);
        $when = $this->chat->local($class->starts_at)->format('l j F, H:i');
        $card = ['type' => 'class', 'service' => $class->title, 'when' => $when, 'price' => $class->price_cents > 0 ? $this->chat->money($class->price_cents) : 'Free'];
        if ($price > 0) {
            $pay = $this->deposits->start($place, $price, (string) $customer['email'], $this->chat->returnUrl());
            if ($pay === null) {
                $place->update(['status' => 'expired']); // don't hold a place nobody can pay for
                throw new ReceptionistRefused('Online payment isn’t available right now, so this can’t be booked here. Offer to pass it to a person.');
            }

            return ['booked' => false, 'awaiting_payment' => true, 'when' => $when, 'price' => $this->chat->money($price), 'pay_url' => $pay,
                'tell_the_customer' => 'The place is held while they pay with the link; it’s confirmed once paid.',
                'card' => [...$card, 'action' => ['label' => 'Pay for the class', 'url' => $pay]]];
        }

        return ['booked' => true, 'class' => $class->title, 'when' => $when,
            'tell_the_customer' => 'Booked. A confirmation is on its way, with a link to cancel the place.',
            'card' => [...$card, 'action' => ['label' => 'See or cancel', 'url' => ManageLink::forClass($place, $this->context->current()->handle())]]];
    }
}
