<?php

declare(strict_types=1);

namespace Wurk\Bookings\Receptionist;

use Carbon\CarbonImmutable;
use Wurk\Bookings\Support\Availability;
use Wurk\Platform\Contracts\ReceptionistRefused;
use Wurk\Platform\Contracts\ReceptionistTool;

/** Free times for a service on a day — the only times the receptionist may offer. */
final readonly class FreeTimesTool implements ReceptionistTool
{
    public const SHOWN = 16;

    public function __construct(private ChatBookings $chat, private Availability $availability) {}

    public function name(): string
    {
        return 'free_times';
    }

    public function description(): string
    {
        return 'Free times for one service on one day. Only offer times this returns. If the day has none, try the next few days.';
    }

    public function parameters(): array
    {
        return ['type' => 'object', 'properties' => [
            'service' => ['type' => 'string', 'description' => 'The service’s id (from services) or name'],
            'date' => ['type' => 'string', 'description' => 'The day, as YYYY-MM-DD'],
        ], 'required' => ['service', 'date']];
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
        $date = (string) ($arguments['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new ReceptionistRefused('Give the day as YYYY-MM-DD.');
        }
        $day = CarbonImmutable::parse($date, $this->chat->local(now())->timezone);
        $times = array_slice($this->availability->on($service, $date), 0, self::SHOWN);

        return [
            'service' => $service->name, 'service_id' => $service->id, 'day' => $day->format('l j F Y'), 'today_is' => $this->chat->local(now())->format('l j F Y'),
            'price' => $this->chat->money($service->price_cents), 'minutes' => $service->duration_minutes,
            'times' => array_map(fn (array $t) => ['starts_at' => $t['starts_at'], 'time' => $this->chat->local(CarbonImmutable::parse($t['starts_at']))->format('H:i')], $times),
        ];
    }
}
