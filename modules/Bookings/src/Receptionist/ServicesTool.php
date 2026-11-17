<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Deposits;
use Wurk\Platform\Contracts\ReceptionistTool;

/** The services a customer can book: name, how long, price, deposit, in person or online. */
final readonly class ServicesTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private Deposits $deposits) {}

    public function name(): string
    {
        return 'services';
    }

    public function description(): string
    {
        return 'The services customers can book, with how long each takes, its price and any deposit. Use it for questions about services or prices, and before checking free times.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => new \stdClass];
    }

    public function capability(): string
    {
        return 'answer';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function run(array $arguments, array $context): array
    {
        return ['services' => BookingService::query()->where('active', true)->orderBy('position')->orderBy('name')->get()->map(fn (BookingService $s) => [
            'id' => $s->id, 'name' => $s->name, 'about' => $s->description, 'minutes' => $s->duration_minutes,
            'price' => $this->chat->money($s->price_cents), 'price_cents' => $s->price_cents,
            'deposit' => $this->chat->money($this->deposits->amountFor($s) ?: null),
            'where' => match ($s->location_type) {
                'online' => 'online only', 'either' => 'in person or online', default => 'in person'
            },
        ])->all()];
    }
}
