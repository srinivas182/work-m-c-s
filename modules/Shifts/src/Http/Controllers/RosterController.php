<?php

declare(strict_types=1);

namespace Wurk\Shifts\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Shifts\Support\Clock;
use Wurk\Shifts\Support\Earnings;
use Wurk\Shifts\Support\Roster;

/** Team → Shifts (Sprint 26.4): the owner's roster, requests, clock-in rule, commission and the month's earnings. */
final class RosterController
{
    public function __construct(private Roster $roster, private Clock $clock, private Earnings $earnings, private TeamDirectory $team) {}

    public function show(Request $request): Response
    {
        $monday = $this->roster->monday($request->query('week'));
        $members = array_values(array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['name']], array_filter($this->team->roster(), fn (array $m) => $m['active'])));

        return Inertia::render('Shifts::Roster', [
            'week' => ['monday' => $monday->toDateString(), 'days' => array_map(fn (int $i) => ['date' => $monday->addDays($i)->toDateString(), 'label' => $monday->addDays($i)->format('D j M')], range(0, 6)),
                'previous' => $monday->subWeek()->toDateString(), 'next' => $monday->addWeek()->toDateString()],
            'members' => $members,
            'shifts' => $this->roster->shifts($monday, $monday->addWeek()),
            'requests' => array_map(fn (array $r) => [...$r, 'name' => collect($members)->firstWhere('id', $r['member_id'])['name'] ?? '',
                'with' => $r['swap_with'] ? (collect($members)->firstWhere('id', $r['swap_with'])['name'] ?? '') : null], $this->roster->requests(status: 'pending')),
            'clock' => $this->clock->settings(),
            'rates' => $this->earnings->rates(),
            'myIp' => $request->ip(),
        ]);
    }

    public function fill(Request $request): RedirectResponse
    {
        $monday = $this->roster->monday((string) $request->input('week'));
        $n = $this->roster->fill($monday, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $n === 0 ? __('Nothing to add: everyone’s booking hours are already on the roster.') : __(':n shifts added from booking hours.', ['n' => $n]));
    }

    public function store(Request $request): RedirectResponse
    {
        $d = $request->validate(['member_id' => ['required', 'string'], 'on' => ['required', 'date_format:Y-m-d'], 'starts' => ['required', 'date_format:H:i'], 'ends' => ['required', 'date_format:H:i']]);
        $this->roster->add($d['member_id'], null, $d['on'], $d['starts'], $d['ends'], (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Shift added.'));
    }

    public function destroy(Request $request, string $shift): RedirectResponse
    {
        $this->roster->remove($shift, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Shift removed.'));
    }

    public function decide(Request $request, string $shiftRequest): RedirectResponse
    {
        $approve = (bool) $request->validate(['approve' => ['required', 'boolean']])['approve'];
        $this->roster->decide($shiftRequest, $approve, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', $approve ? __('Approved.') : __('Declined.'));
    }

    public function clockSettings(Request $request): RedirectResponse
    {
        $d = $request->validate(['clock_check' => ['required', 'in:none,wifi,location'], 'wifi' => ['array', 'max:5'], 'wifi.*' => ['ip'],
            'places' => ['array', 'max:5'], 'places.*.name' => ['required', 'string', 'max:60'], 'places.*.lat' => ['required', 'numeric', 'between:-90,90'], 'places.*.lng' => ['required', 'numeric', 'between:-180,180']]);
        $this->clock->saveSettings($d['clock_check'], array_values($d['wifi'] ?? []), array_values(array_map(fn (array $p) => ['name' => strip_tags($p['name']), 'lat' => (float) $p['lat'], 'lng' => (float) $p['lng']], $d['places'] ?? [])),
            (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Saved.'));
    }

    public function earnings(Request $request): Response
    {
        $start = $this->earnings->monthStart($request->query('month'));

        return Inertia::render('Shifts::Earnings', ['month' => ['value' => $start->format('Y-m'), 'label' => $start->format('F Y'), 'previous' => $start->subMonth()->format('Y-m'),
            'next' => $start->addMonth()->format('Y-m')], ...$this->earnings->month($start),
            'members' => array_values(array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['name']], array_filter($this->team->roster(), fn (array $m) => $m['active'])))]);
    }

    public function export(Request $request): HttpResponse
    {
        $start = $this->earnings->monthStart($request->query('month'));

        return new HttpResponse($this->earnings->csv($start), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="team-earnings-'.$start->format('Y-m').'.csv"', 'Cache-Control' => 'no-store, private']);
    }

    public function rate(Request $request, string $member): RedirectResponse
    {
        $d = $request->validate(['services' => ['required', 'numeric', 'between:0,100'], 'products' => ['required', 'numeric', 'between:0,100']]);
        $this->earnings->setRate($member, (int) round((float) $d['services'] * 100), (int) round((float) $d['products'] * 100), (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Commission saved.'));
    }

    public function tip(Request $request): RedirectResponse
    {
        $d = $request->validate(['member_id' => ['required', 'string'], 'amount' => ['required', 'numeric', 'min:0.01', 'max:100000']]);
        $this->earnings->tip($d['member_id'], (int) round((float) $d['amount'] * 100), null, null, (string) $request->user()?->getAuthIdentifier());

        return back()->with('status', __('Tip recorded.'));
    }
}
