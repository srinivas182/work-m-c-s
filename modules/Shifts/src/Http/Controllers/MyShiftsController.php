<?php

declare(strict_types=1);

namespace Wurk\Shifts\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Shifts\Support\Clock;
use Wurk\Shifts\Support\Roster;
use Wurk\Tenancy\Contracts\TenantContext;

/** My shifts (Sprint 26.4): each team member's own week, leave and swaps, and clocking in and out — on their phone. */
final class MyShiftsController
{
    public function __construct(private Roster $roster, private Clock $clock, private TeamDirectory $team, private TenantContext $context) {}

    public function show(Request $request): Response
    {
        $me = $this->member($request);
        $monday = $this->roster->monday(null);
        $tz = $this->context->current()->timezone();
        $others = array_values(array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['name']], array_filter($this->team->roster(), fn (array $m) => $m['active'] && $m['id'] !== $me['id'])));

        return Inertia::render('Shifts::Mine', [
            'shifts' => array_map(fn (array $s) => [...$s, 'label' => CarbonImmutable::parse($s['on'], $tz)->format('D j M')], $this->roster->shifts($monday, $monday->addWeeks(2), $me['id'])),
            'requests' => $this->roster->requests($me['id']),
            'others' => $others,
            'clockedIn' => $this->clock->open($me['id']),
            'check' => $this->clock->settings()['clock_check'],
        ]);
    }

    public function leave(Request $request): RedirectResponse
    {
        $d = $request->validate(['from' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'until' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->roster->askLeave($this->member($request)['id'], $d['from'], $d['until'], isset($d['note']) ? strip_tags($d['note']) : null);

        return back()->with('status', __('Asked. The owner will answer.'));
    }

    public function swap(Request $request): RedirectResponse
    {
        $d = $request->validate(['shift_id' => ['required', 'string'], 'swap_with' => ['required', 'string'], 'note' => ['nullable', 'string', 'max:300']]);
        $this->roster->askSwap($this->member($request)['id'], $d['shift_id'], $d['swap_with'], isset($d['note']) ? strip_tags($d['note']) : null);

        return back()->with('status', __('Asked. The owner will answer.'));
    }

    public function clockIn(Request $request): RedirectResponse
    {
        $d = $request->validate(['lat' => ['nullable', 'numeric', 'between:-90,90'], 'lng' => ['nullable', 'numeric', 'between:-180,180']]);
        $this->clock->in($this->member($request)['id'], (string) $request->ip(), isset($d['lat']) ? (float) $d['lat'] : null, isset($d['lng']) ? (float) $d['lng'] : null);

        return back()->with('status', __('Clocked in at :time.', ['time' => now($this->context->current()->timezone())->format('H:i')]));
    }

    public function clockOut(Request $request): RedirectResponse
    {
        $minutes = $this->clock->out($this->member($request)['id']);

        return back()->with('status', __('Clocked out. You worked :h h :m min.', ['h' => intdiv($minutes, 60), 'm' => $minutes % 60]));
    }

    /** @return array{id: string, identity_id: string, name: string} */
    private function member(Request $request): array
    {
        $me = (string) $request->user()?->getAuthIdentifier();
        $m = collect($this->team->roster())->first(fn (array $m) => $m['identity_id'] === $me && $m['active']);
        abort_if($m === null, 404);

        return ['id' => $m['id'], 'identity_id' => $m['identity_id'], 'name' => $m['name']];
    }
}
