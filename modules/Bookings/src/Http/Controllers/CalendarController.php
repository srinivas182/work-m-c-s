<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners and staff: the bookings calendar — day or week, per team member. */
final class CalendarController
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private Booker $booker) {}

    public function show(Request $request): Response
    {
        $tz = $this->context->current()->timezone();
        $view = $request->query('view') === 'week' ? 'week' : 'day';
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1 ? (string) $request->query('date') : CarbonImmutable::now($tz)->format('Y-m-d');
        $start = CarbonImmutable::parse($date, $tz)->startOfDay();
        $start = $view === 'week' ? $start->startOfWeek() : $start;
        $end = $start->addDays($view === 'week' ? 7 : 1);
        $services = BookingService::query()->orderBy('position')->orderBy('name')->get(['id', 'name', 'duration_minutes', 'active']);
        $names = $services->pluck('name', 'id');

        return Inertia::render('Bookings::Calendar', [
            'view' => $view,
            'date' => $date,
            'start' => $start->format('Y-m-d'),
            'days' => $view === 'week' ? 7 : 1,
            'timezone' => $tz,
            'team' => $this->team->members(),
            'services' => $services->where('active', true)->values()->map(fn (BookingService $s) => ['id' => $s->id, 'name' => $s->name, 'duration' => $s->duration_minutes])->all(),
            'bookings' => Booking::query()->where('starts_at', '>=', Instant::db($start))->where('starts_at', '<', Instant::db($end))
                ->where('status', '!=', 'cancelled')->orderBy('starts_at')->get()->map(fn (Booking $b) => [
                    'id' => $b->id, 'member_id' => $b->member_id, 'service' => (string) ($names[$b->service_id] ?? 'Service'),
                    'customer' => $b->customer_name, 'phone' => $b->customer_phone, 'notes' => $b->notes, 'status' => $b->status, 'source' => $b->source,
                    'starts_at' => $b->starts_at->setTimezone($tz)->toIso8601String(), 'ends_at' => $b->ends_at->setTimezone($tz)->toIso8601String(),
                ])->all(),
            'canManage' => $request->user()?->can('bookings.manage') ?? false,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'service' => ['required', 'string', Rule::exists($this->context->connectionName().'.booking_services', 'id')],
            'member' => ['nullable', 'string', Rule::in(array_column($this->team->members(), 'id'))],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $service = BookingService::query()->whereKey($data['service'])->firstOrFail();
        try {
            $this->booker->book($service, $this->at($data['date'], $data['time']),
                ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null],
                $data['member'] ?? null, source: 'owner', by: (string) $request->user()?->getAuthIdentifier(), asOwner: true);
        } catch (SlotTaken) {
            throw ValidationException::withMessages(['time' => 'That time clashes with another booking. Choose another time or team member.']);
        }

        return back()->with('status', 'Booking added.');
    }

    public function move(Request $request, string $booking): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'member' => ['nullable', 'string', Rule::in(array_column($this->team->members(), 'id'))],
        ]);
        try {
            $this->booker->move(Booking::query()->whereKey($booking)->firstOrFail(), $this->at($data['date'], $data['time']), $data['member'] ?? null, (string) $request->user()?->getAuthIdentifier());
        } catch (SlotTaken) {
            throw ValidationException::withMessages(['time' => 'That time clashes with another booking. The booking hasn’t moved.']);
        }

        return back()->with('status', 'Booking moved.');
    }

    public function status(Request $request, string $booking): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', Rule::in(['cancelled', 'no_show', 'completed'])]])['status'];
        $model = Booking::query()->whereKey($booking)->firstOrFail();
        $by = (string) $request->user()?->getAuthIdentifier();
        if ($status === 'completed') {
            $model->update(['status' => 'completed']);
        } elseif ($status === 'cancelled') {
            if (app(CancellationPolicy::class)->businessCancels($model, $by)['refunded']) {
                return back()->with('status', 'Booking cancelled. The deposit is being refunded.');
            }
        } else {
            $this->booker->cancel($model, $by, $status); // a no-show keeps any deposit; frees the time
        }

        return back()->with('status', ['cancelled' => 'Booking cancelled.', 'no_show' => 'Marked as a no-show.', 'completed' => 'Marked as done.'][$status]);
    }

    private function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("{$date} {$time}", $this->context->current()->timezone());
    }
}
