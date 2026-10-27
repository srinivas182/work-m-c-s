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
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\MemberMeetingLink;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\MeetingLinks;
use Wurk\Messaging\Contracts\Messenger;
use Wurk\Messaging\Contracts\ProviderCredentials;
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
            'video' => [
                'links' => ['default' => app(MeetingLinks::class)->default(), 'members' => MemberMeetingLink::query()->pluck('url', 'member_id')->all()],
                'connection' => MeetingConnection::query()->find($this->context->id())?->only(['provider', 'account_label', 'fixed_url']),
                'available' => ['google' => ! empty(app(ProviderCredentials::class)->active('meetings.google')['credentials']['client_id'] ?? null),
                    'zoom' => ! empty(app(ProviderCredentials::class)->active('meetings.zoom')['credentials']['client_id'] ?? null)],
            ],
            'messages' => [
                'channel' => BookingSettings::query()->find($this->context->id())->message_channel ?? 'email',
                'remind_24h' => (bool) (BookingSettings::query()->find($this->context->id())->remind_24h ?? true),
                'remind_2h' => (bool) (BookingSettings::query()->find($this->context->id())->remind_2h ?? false),
                'available' => ['sms' => app(Messenger::class)->channelActive('sms'), 'whatsapp' => app(Messenger::class)->channelActive('whatsapp')],
            ],
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
            'deposit_type' => ['required', Rule::in(['none', 'fixed', 'percent', 'full'])],
            'deposit_when' => ['sometimes', Rule::in(['always', 'online'])],
            'deposit_value' => ['required', 'integer', 'min:0', $request->input('deposit_type') === 'percent' ? 'max:100' : 'max:100000000'],
            'location_type' => ['sometimes', Rule::in(['in_person', 'online', 'either'])],
            'active' => ['boolean'],
            'members' => ['array', 'min:1'],
            'members.*' => ['string', Rule::in($memberIds)],
        ], ['members.min' => 'Choose at least one team member who does this service.']);
        $data['location_type'] ??= 'in_person'; // omitted means in person, as stored by default
        $data['deposit_when'] ??= 'always';
        if ($data['location_type'] !== 'in_person' && ! $this->reachable((array) ($data['members'] ?? []))) {
            throw ValidationException::withMessages(['location_type' => 'Add a meeting link (under Meeting links) for everyone who does this service, or a link for the whole business, so clients can join.']);
        }

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

    public function saveMessages(Request $request): RedirectResponse
    {
        $data = $request->validate(['channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])], 'remind_24h' => ['boolean'], 'remind_2h' => ['boolean']]);
        BookingSettings::query()->updateOrCreate(['tenant_id' => $this->context->id()], [
            'message_channel' => $data['channel'], 'remind_24h' => (bool) ($data['remind_24h'] ?? false), 'remind_2h' => (bool) ($data['remind_2h'] ?? false),
        ]);

        return back()->with('status', 'Message settings saved.');
    }

    /**
     * Can everyone who does a service be reached online? (Their own meeting link, or a business default.)
     *
     * @param  array<int|string, mixed>  $members
     */
    private function reachable(array $members): bool
    {
        $links = app(MeetingLinks::class);
        $provider = MeetingConnection::query()->find($this->context->id())?->provider;
        if (in_array($provider, ['google', 'zoom'], true) || $links->default() !== null) {
            return true; // a connected account (if WURK enables it) or a link for the whole business
        }

        return $members !== [] && collect($members)->every(fn ($m) => $links->forMember((string) $m) !== null);
    }

    /** Meeting links: one per team member, plus a default for the whole business. */
    public function saveLinks(Request $request): RedirectResponse
    {
        $team = array_column($this->team->members(), 'id');
        $data = $request->validate([
            'default' => ['nullable', 'url:https', 'max:500'],
            'members' => ['array'], 'members.*' => ['nullable', 'url:https', 'max:500'],
        ], ['default.url' => 'Enter the full meeting link, starting with https://', 'members.*.url' => 'Enter the full meeting link, starting with https://']);
        foreach (array_keys($data['members'] ?? []) as $member) {
            if (! in_array($member, $team, true)) {
                throw ValidationException::withMessages(['members' => 'That person isn’t on your team.']);
            }
        }
        DB::connection($this->context->connectionName())->transaction(function () use ($data): void {
            if (! empty($data['default'])) {
                MeetingConnection::query()->updateOrCreate(['tenant_id' => $this->context->id()], ['provider' => 'fixed', 'fixed_url' => $data['default'], 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'account_label' => null]);
            } else {
                MeetingConnection::query()->where('provider', 'fixed')->delete();
            }
            foreach ($data['members'] ?? [] as $member => $url) {
                empty($url)
                    ? MemberMeetingLink::query()->whereKey($member)->delete()
                    : MemberMeetingLink::query()->updateOrCreate(['member_id' => $member], ['url' => $url]);
            }
        });
        $this->audit->record('bookings.meeting_links_saved', 'booking_settings', $this->context->id(), []);

        return back()->with('status', 'Meeting links saved.');
    }
}
