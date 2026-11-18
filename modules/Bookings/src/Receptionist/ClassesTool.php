<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\Instant;
use Wurk\Platform\Contracts\ReceptionistTool;

/** Group classes in the next two weeks, with places left — the same list as the website's class timetable. */
final readonly class ClassesTool implements ReceptionistTool
{
    public function __construct(private ChatBookings $chat, private Deposits $deposits) {}

    public function name(): string
    {
        return 'classes';
    }

    public function description(): string
    {
        return 'Group classes in the next 14 days: title, day and time, length, price and places left. Use before offering or booking a class place.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => ['search' => ['type' => 'string', 'description' => 'Optional words from the class name, e.g. "yoga"']]];
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
        $search = mb_strtolower(trim((string) ($arguments['search'] ?? '')));
        $sessions = ClassSession::query()->where('status', 'scheduled')->where('starts_at', '>', Instant::db(now()))
            ->where('starts_at', '<', Instant::db(now()->addDays(14)))->orderBy('starts_at')->limit(60)->get()
            ->filter(fn (ClassSession $s) => $search === '' || str_contains(mb_strtolower($s->title), $search))->take(20);

        return ['classes' => $sessions->map(fn (ClassSession $s) => [
            'class_id' => $s->id, 'title' => $s->title, 'when' => $this->chat->local($s->starts_at)->format('l j F, H:i'),
            'minutes' => (int) $s->starts_at->diffInMinutes($s->ends_at), 'online' => $s->location_type === 'online',
            'price' => $s->price_cents > 0 ? $this->chat->money($s->price_cents) : 'Free',
            'paid' => $s->price_cents <= 0 ? null : ($this->deposits->amountForClass($s) > 0 ? 'online when booking' : 'at the class'),
            'places_left' => max(0, $s->capacity - $s->taken()),
        ])->values()->all()];
    }
}
