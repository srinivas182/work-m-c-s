<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Models\ReservationHours;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Bookings\Support\Reserver;
use Wurk\Bookings\Support\TableFull;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Bookings → Tables — tonight's reservations, adding one, and table settings. */
final class TablesController
{
    public function __construct(private TenantContext $context, private Reserver $reserver) {}

    public function show(Request $request): Response
    {
        $tz = $this->context->current()->timezone();
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1 ? (string) $request->query('date') : CarbonImmutable::now($tz)->format('Y-m-d');
        $settings = ReservationSettings::query()->find($this->context->id());

        return Inertia::render('Bookings::Tables', [
            'date' => $date,
            'timezone' => $tz,
            'reservations' => Reservation::query()->where('service_date', $date)->where('status', '!=', 'cancelled')->orderBy('starts_at')->get()
                ->map(fn (Reservation $r) => ['id' => $r->id, 'party' => $r->party_size, 'customer' => $r->customer_name, 'phone' => $r->customer_phone,
                    'notes' => $r->notes, 'status' => $r->status, 'source' => $r->source, 'time' => $r->starts_at->setTimezone($tz)->format('H:i')])->all(),
            'settings' => [
                'enabled' => (bool) $settings?->enabled, 'covers' => $settings->covers ?? 40, 'sitting_minutes' => $settings->sitting_minutes ?? 90,
                'step_minutes' => $settings->step_minutes ?? 30, 'max_party' => $settings->max_party ?? 8, 'notice_minutes' => $settings->notice_minutes ?? 60,
            ],
            'hours' => ReservationHours::query()->orderBy('weekday')->orderBy('starts')->get()
                ->map(fn (ReservationHours $h) => ['weekday' => $h->weekday, 'starts' => substr($h->starts, 0, 5), 'ends' => substr($h->ends, 0, 5)])->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'], 'time' => ['required', 'date_format:H:i'], 'party' => ['required', 'integer', 'min:1', 'max:50'],
            'name' => ['required', 'string', 'max:120'], 'phone' => ['nullable', 'string', 'max:40'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        try {
            $this->reserver->reserve(CarbonImmutable::parse("{$data['date']} {$data['time']}", $this->context->current()->timezone()), (int) $data['party'],
                ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'notes' => $data['notes'] ?? null], 'owner', asOwner: true);
        } catch (TableFull) {
            throw ValidationException::withMessages(['time' => 'There aren’t enough seats free at that time.']);
        }

        return back()->with('status', 'Reservation added.');
    }

    public function status(Request $request, string $reservation): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', Rule::in(['seated', 'no_show', 'cancelled'])]])['status'];
        $this->reserver->setStatus(Reservation::query()->whereKey($reservation)->firstOrFail(), $status, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', ['seated' => 'Marked as seated.', 'no_show' => 'Marked as a no-show.', 'cancelled' => 'Reservation cancelled.'][$status]);
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $time = ['required', 'date_format:H:i'];
        $data = $request->validate([
            'enabled' => ['boolean'], 'covers' => ['required', 'integer', 'min:1', 'max:1000'], 'sitting_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'step_minutes' => ['required', Rule::in([15, 30, 60])], 'max_party' => ['required', 'integer', 'min:1', 'max:50'], 'notice_minutes' => ['required', 'integer', 'min:0', 'max:2880'],
            'hours' => ['present', 'array', 'max:28'], 'hours.*.weekday' => ['required', 'integer', 'between:0,6'], 'hours.*.starts' => $time, 'hours.*.ends' => $time,
        ]);
        foreach ($data['hours'] as $i => $h) {
            if ($h['ends'] < $h['starts']) {
                throw ValidationException::withMessages(["hours.{$i}.ends" => 'The last arrival must be after the first.']);
            }
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($data): void {
            ReservationSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], Arr::except($data, ['hours']));
            ReservationHours::query()->delete();
            foreach ($data['hours'] as $h) {
                ReservationHours::query()->create($h);
            }
        });

        return back()->with('status', 'Table settings saved.');
    }
}
