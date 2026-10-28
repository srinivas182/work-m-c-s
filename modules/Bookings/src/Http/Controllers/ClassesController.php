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
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\MemberMeetingLink;
use Wurk\Bookings\Support\ClassBooker;
use Wurk\Bookings\Support\ClassFull;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\MeetingLinks;
use Wurk\Payments\Contracts\BusinessPayments;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Bookings → Classes — schedule classes, see who's coming, mark attendance, cancel. */
final class ClassesController
{
    public function __construct(private TenantContext $context, private ClassBooker $booker, private TeamDirectory $team) {}

    public function show(): Response
    {
        $tz = $this->context->current()->timezone();
        $sessions = ClassSession::query()->where('ends_at', '>', Instant::db(now()->subDay()))->orderBy('starts_at')->limit(100)->get();
        $attendees = ClassAttendee::query()->whereIn('session_id', $sessions->pluck('id'))->where('status', '!=', 'cancelled')->orderBy('created_at')->get()->groupBy('session_id');

        return Inertia::render('Bookings::Classes', [
            'timezone' => $tz,
            'team' => $this->team->members(),
            'canGoOnline' => MeetingConnection::query()->find($this->context->id()) !== null || MemberMeetingLink::query()->exists(),
            'canTakePayments' => app(BusinessPayments::class)->available() !== [],
            'classes' => $sessions->map(fn (ClassSession $s) => [
                'id' => $s->id, 'title' => $s->title, 'status' => $s->status, 'online' => $s->location_type === 'online', 'capacity' => $s->capacity, 'paid' => $s->pay_online,
                'when' => $s->starts_at->setTimezone($tz)->format('D j M, H:i'), 'minutes' => (int) $s->starts_at->diffInMinutes($s->ends_at),
                'attendees' => ($attendees[$s->id] ?? collect())->map(fn (ClassAttendee $a) => ['id' => $a->id, 'name' => $a->customer_name, 'phone' => $a->customer_phone, 'status' => $a->status])->values()->all(),
            ])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:500'],
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'minutes' => ['required', 'integer', 'min:10', 'max:480'],
            'capacity' => ['required', 'integer', 'min:1', 'max:1000'], 'location_type' => ['required', Rule::in(['in_person', 'online'])],
            'member' => ['nullable', 'string', Rule::in(array_column($this->team->members(), 'id'))], 'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'pay_online' => ['boolean'],
        ]);
        $health = app(HealthPractice::class);
        if ($data['location_type'] === 'online' && $health->on() && $health->unregistered(array_values(array_filter([(string) ($data['member'] ?? '')]))) !== []) {
            throw ValidationException::withMessages(['location_type' => 'Online classes at a health practice need the instructor’s registration number (under Booking settings → Health practice).']);
        }
        $connected = in_array(MeetingConnection::query()->find($this->context->id())?->provider, ['google', 'zoom'], true);
        if ($data['location_type'] === 'online' && ! $connected && app(MeetingLinks::class)->forMember($data['member'] ?? null) === null) {
            throw ValidationException::withMessages(['location_type' => 'Add a meeting link for the instructor (or the whole business) under Meeting links in Booking settings, so people can join.']);
        }
        if (($data['pay_online'] ?? false) && ($data['price_cents'] < 100 || app(BusinessPayments::class)->available() === [])) {
            throw ValidationException::withMessages(['pay_online' => 'To take payment online, connect PayFast or Yoco under Settings → Payments, and set a price.']);
        }
        $start = CarbonImmutable::parse("{$data['date']} {$data['time']}", $this->context->current()->timezone());
        if ($start->isPast()) {
            throw ValidationException::withMessages(['time' => 'Choose a time in the future.']);
        }
        ClassSession::query()->create([
            'title' => $data['title'], 'description' => $data['description'] ?? null, 'starts_at' => Instant::db($start), 'ends_at' => Instant::db($start->addMinutes($data['minutes'])),
            'capacity' => $data['capacity'], 'location_type' => $data['location_type'], 'member_id' => $data['member'] ?? null, 'price_cents' => $data['price_cents'],
            'pay_online' => (bool) ($data['pay_online'] ?? false),
        ]);

        return back()->with('status', 'Class scheduled.');
    }

    /** Add someone by hand (a phone booking, a walk-in) — the capacity still holds. */
    public function addAttendee(Request $request, string $session): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:160']]);
        try {
            $this->booker->attend(ClassSession::query()->whereKey($session)->firstOrFail(), $data, 'owner');
        } catch (ClassFull) {
            throw ValidationException::withMessages(['name' => 'This class is full.']);
        }

        return back()->with('status', 'Added to the class.');
    }

    public function attendance(Request $request, string $attendee): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', Rule::in(['attended', 'no_show', 'cancelled'])]])['status'];
        $a = ClassAttendee::query()->whereKey($attendee)->firstOrFail();
        $status === 'cancelled' ? $this->booker->leave($a, (string) $request->user()?->getAuthIdentifier()) : $a->update(['status' => $status]);

        return back()->with('status', ['attended' => 'Marked as attended.', 'no_show' => 'Marked as a no-show.', 'cancelled' => 'Place cancelled.'][$status]);
    }

    public function cancel(Request $request, string $session): RedirectResponse
    {
        $n = $this->booker->cancelSession(ClassSession::query()->whereKey($session)->firstOrFail(), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $n > 0 ? "Class cancelled. {$n} ".($n === 1 ? 'person has' : 'people have').' been told.' : 'Class cancelled.');
    }
}
