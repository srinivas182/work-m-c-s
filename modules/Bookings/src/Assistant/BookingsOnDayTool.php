<?php

declare(strict_types=1);

namespace Wurk\Bookings\Assistant;

use Carbon\CarbonImmutable;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Platform\Contracts\AssistantReadTool;
use Wurk\Platform\Contracts\AssistantRefused;
use Wurk\Tenancy\Contracts\TenantContext;

/**
 * "Ask WURK" (16.2): one day's bookings — times, services, who's working, status. Customers' names and contact details
 * stay out (this goes to an AI provider). Only the branches this person may see.
 */
final readonly class BookingsOnDayTool implements AssistantReadTool
{
    public function __construct(private TenantContext $context, private TeamDirectory $team) {}

    public function name(): string
    {
        return 'bookings_on_day';
    }

    public function description(): string
    {
        return 'List one day’s bookings: time, service, team member and status (no customer details).';
    }

    public function parameters(): array
    {
        return ['date' => 'the day, YYYY-MM-DD'];
    }

    public function permission(): string
    {
        return 'bookings.view';
    }

    public function entitlement(): string
    {
        return 'bookings';
    }

    public function read(array $params): string
    {
        $tz = $this->context->current()->timezone();
        $date = (string) ($params['date'] ?? '');
        $day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $date, $tz) : null;
        if (! $day instanceof CarbonImmutable) {
            throw new AssistantRefused('Which day? I didn’t understand the date.');
        }
        $stored = (string) config('app.timezone');
        $bookings = Booking::query()->where('starts_at', '>=', $day->setTimezone($stored))->where('starts_at', '<', $day->addDay()->setTimezone($stored))
            ->orderBy('starts_at')->limit(200)->get(['id', 'service_id', 'member_id', 'starts_at', 'ends_at', 'status']);
        $services = BookingService::query()->whereIn('id', $bookings->pluck('service_id')->unique())->pluck('name', 'id');
        $members = array_column($this->team->members(), 'name', 'id');
        $kept = $bookings->where('status', '!=', 'cancelled');
        $lines = [$day->format('l j F Y').': '.$kept->count().' booking(s), '.($bookings->count() - $kept->count()).' cancelled.'];
        foreach ($bookings as $b) {
            $lines[] = CarbonImmutable::parse($b->starts_at)->setTimezone($tz)->format('H:i').'–'.CarbonImmutable::parse($b->ends_at)->setTimezone($tz)->format('H:i')
                .' '.($services[$b->service_id] ?? 'Service').' with '.($members[$b->member_id] ?? 'a team member').' ('.str_replace('_', ' ', $b->status).')';
        }

        return implode("\n", $lines);
    }
}
