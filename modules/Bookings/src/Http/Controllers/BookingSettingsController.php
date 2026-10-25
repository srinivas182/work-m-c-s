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
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Billing\Contracts\SplitPayments;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\Instant;
use Wurk\Tenancy\Contracts\TenantContext;

/** Owners: Bookings → Settings — services, team hours and time off. */
final class BookingSettingsController
{
    public function __construct(private TenantContext $context, private TeamDirectory $team, private AuditLog $audit) {}

    public function show(): Response
    {
        $db = DB::connection($this->context->connectionName());

        return Inertia::render('Bookings::Settings', [
            'services' => BookingService::query()->orderBy('position')->orderBy('name')->get()->map(fn (BookingService $s) => [
                ...$s->only(['id', 'name', 'description', 'duration_minutes', 'buffer_before_minutes', 'buffer_after_minutes', 'price_cents', 'deposit_type', 'deposit_value', 'active']),
                'members' => $db->table('booking_service_member')->where('service_id', $s->id)->pluck('member_id')->all(),
            ])->all(),
            'team' => $this->team->members(),
            'hours' => BookingHours::query()->orderBy('weekday')->orderBy('starts')->get(['member_id', 'weekday', 'starts', 'ends'])
                ->map(fn (BookingHours $h) => ['member_id' => $h->member_id, 'weekday' => $h->weekday, 'starts' => substr($h->starts, 0, 5), 'ends' => substr($h->ends, 0, 5)])->all(),
            'timeOff' => TimeOff::query()->where('ends_at', '>', now())->orderBy('starts_at')->get()
                ->map(fn (TimeOff $t) => ['id' => $t->id, 'member_id' => $t->member_id, 'starts_at' => $t->starts_at->toIso8601String(), 'ends_at' => $t->ends_at->toIso8601String(), 'reason' => $t->reason])->all(),
            'timezone' => $this->context->current()->timezone(),
            'payouts' => ['label' => BookingSettings::query()->find($this->context->id())?->payout_label],
            'banks' => fn () => app(SplitPayments::class)->banks(),
            'policy' => ['hours' => app(CancellationPolicy::class)->hours(), 'text' => (string) BookingSettings::query()->find($this->context->id())?->policy,
                'default' => CancellationPolicy::defaultWording(app(CancellationPolicy::class)->hours())],
        ]);
    }

    public function saveService(Request $request, ?string $service = null): RedirectResponse
    {
        $memberIds = array_column($this->team->members(), 'id');
        $five = fn (string $attr, mixed $v, \Closure $fail) => (int) $v % 5 === 0 ?: $fail('Use steps of 5 minutes.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480', $five],
            'buffer_before_minutes' => ['required', 'integer', 'min:0', 'max:120', $five],
            'buffer_after_minutes' => ['required', 'integer', 'min:0', 'max:120', $five],
            'price_cents' => ['required', 'integer', 'min:0', 'max:100000000'],
            'deposit_type' => ['required', Rule::in(['none', 'fixed', 'percent'])],
            'deposit_value' => ['required', 'integer', 'min:0', $request->input('deposit_type') === 'percent' ? 'max:100' : 'max:100000000'],
            'active' => ['boolean'],
            'members' => ['array', 'min:1'],
            'members.*' => ['string', Rule::in($memberIds)],
        ], ['members.min' => 'Choose at least one team member who does this service.']);

        DB::connection($this->context->connectionName())->transaction(function () use ($data, $service): void {
            $model = $service === null ? new BookingService : BookingService::query()->findOrFail($service);
            $model->fill(Arr::except($data, ['members']))->save();
            $pivot = DB::connection($this->context->connectionName())->table('booking_service_member');
            $pivot->where('service_id', $model->id)->delete();
            $pivot->insert(array_map(fn (string $m) => ['service_id' => $model->id, 'member_id' => $m, 'tenant_id' => $this->context->id()], array_values(array_unique($data['members']))));
            $this->audit->record($service === null ? 'bookings.service_created' : 'bookings.service_updated', 'booking_service', $model->id, ['name' => $model->name]);
        });

        return back()->with('status', 'Service saved.');
    }

    /** A team member's weekly hours, replaced as a whole. */
    public function saveHours(Request $request, string $member): RedirectResponse
    {
        abort_unless(in_array($member, array_column($this->team->members(), 'id'), true), 404);
        $time = ['required', 'date_format:H:i', fn (string $a, mixed $v, \Closure $fail) => (int) substr((string) $v, 3, 2) % 5 === 0 ?: $fail('Use steps of 5 minutes.')];
        $rows = $request->validate([
            'hours' => ['present', 'array', 'max:28'],
            'hours.*.weekday' => ['required', 'integer', 'between:0,6'],
            'hours.*.starts' => $time,
            'hours.*.ends' => $time,
        ])['hours'];
        foreach ($rows as $i => $row) {
            if ($row['ends'] <= $row['starts']) {
                throw ValidationException::withMessages(["hours.{$i}.ends" => 'The end must be after the start.']);
            }
            foreach ($rows as $j => $other) {
                if ($j !== $i && $other['weekday'] === $row['weekday'] && $other['starts'] < $row['ends'] && $other['ends'] > $row['starts']) {
                    throw ValidationException::withMessages(["hours.{$i}.starts" => 'These hours overlap other hours on the same day.']);
                }
            }
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($member, $rows): void {
            BookingHours::query()->where('member_id', $member)->delete();
            foreach ($rows as $row) {
                BookingHours::query()->create(['member_id' => $member, ...$row]);
            }
        });
        $this->audit->record('bookings.hours_updated', 'member', $member, ['stretches' => count($rows)]);

        return back()->with('status', 'Hours saved.');
    }

    public function addTimeOff(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'member_id' => ['nullable', 'string', Rule::in(array_column($this->team->members(), 'id'))],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);
        $tz = $this->context->current()->timezone();
        $off = TimeOff::query()->create([...$data,
            'starts_at' => Instant::db(CarbonImmutable::parse($data['starts_at'], $tz)),
            'ends_at' => Instant::db(CarbonImmutable::parse($data['ends_at'], $tz))]);
        $this->audit->record('bookings.time_off_added', 'booking_time_off', $off->id, ['whole_business' => $off->member_id === null]);

        return back()->with('status', 'Time off added. Existing bookings in that time are kept — move or cancel them in the calendar.');
    }

    public function removeTimeOff(string $timeOff): RedirectResponse
    {
        TimeOff::query()->findOrFail($timeOff)->delete();

        return back()->with('status', 'Time off removed.');
    }

    /** Connect the business's bank for deposits (a Paystack subaccount). Only a masked label is kept. */
    public function connectPayouts(Request $request, SplitPayments $payments): RedirectResponse
    {
        $data = $request->validate(['bank_code' => ['required', 'string', 'max:20'], 'account_number' => ['required', 'string', 'regex:/^\d{6,16}$/']],
            ['account_number.regex' => 'Enter the account number using digits only.']);
        $bank = collect($payments->banks())->firstWhere('code', $data['bank_code']);
        if ($bank === null) {
            throw ValidationException::withMessages(['bank_code' => 'Choose your bank from the list.']);
        }
        try {
            $code = $payments->createSubaccount($this->context->current()->displayName(), $data['bank_code'], $data['account_number']);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['account_number' => $e->getMessage()]);
        }
        BookingSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], [
            'payout_subaccount' => $code, 'payout_label' => $bank['name'].' ••••'.substr($data['account_number'], -4),
        ]);
        $this->audit->record('bookings.payouts_connected', 'booking_settings', $this->context->id(), ['bank' => $bank['name']]);

        return back()->with('status', 'Deposits will be paid into '.$bank['name'].' ••••'.substr($data['account_number'], -4).'.');
    }

    public function disconnectPayouts(): RedirectResponse
    {
        BookingSettings::query()->whereKey($this->context->id())->update(['payout_subaccount' => null, 'payout_label' => null]);
        $this->audit->record('bookings.payouts_disconnected', 'booking_settings', $this->context->id(), []);

        return back()->with('status', 'Deposits are switched off until you connect a bank account again.');
    }

    public function savePolicy(Request $request): RedirectResponse
    {
        $data = $request->validate(['hours' => ['required', 'integer', 'min:0', 'max:168'], 'text' => ['nullable', 'string', 'max:2000']]);
        BookingSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], ['cancellation_hours' => $data['hours'], 'policy' => $data['text'] ?: null]);

        return back()->with('status', 'Cancellation policy saved.');
    }
}
