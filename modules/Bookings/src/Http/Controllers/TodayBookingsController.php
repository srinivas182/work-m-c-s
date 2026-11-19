<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Audit\Contracts\AuditLog;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Today\MyBookings;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Platform\Contracts\LoyaltyCards;
use Wurk\Platform\Money\Money;

/**
 * Today's buttons on a booking (Sprint 26.2): Start, Done, No-show and "Paid in person" — for the team member it's
 * with (their own bookings), or anyone who manages bookings.
 */
final class TodayBookingsController
{
    public function __construct(private TeamDirectory $team, private Booker $booker, private AuditLog $audit) {}

    public function start(Request $request, string $booking): RedirectResponse
    {
        $b = $this->mine($request, $booking);
        abort_unless($b->status === 'confirmed', 422);
        if ($b->started_at === null) {
            $b->update(['started_at' => now()]);
            $this->audit->record('bookings.started', 'booking', $b->id, ['by' => (string) $request->user()?->getAuthIdentifier()]);
        }

        return back()->with('status', __('Started: :name.', ['name' => $b->customer_name]));
    }

    public function status(Request $request, string $booking): RedirectResponse
    {
        $status = (string) $request->validate(['status' => ['required', Rule::in(['completed', 'no_show'])]])['status'];
        $b = $this->mine($request, $booking);
        abort_unless($b->status === 'confirmed', 422);
        $by = (string) $request->user()?->getAuthIdentifier();
        if ($status === 'completed') {
            $b->update(['status' => 'completed']);
            $this->audit->record('bookings.completed', 'booking', $b->id, ['by' => $by]);
        } else {
            $this->booker->cancel($b, $by, 'no_show');
        }

        return back()->with('status', $status === 'completed' ? __('Marked as done.') : __('Marked as a no-show.'));
    }

    /** What's still to pay, taken at the counter. */
    public function paid(Request $request, string $booking, MyBookings $mine): RedirectResponse
    {
        $method = (string) $request->validate(['method' => ['required', Rule::in(array_keys(InPersonPayments::METHODS))]])['method'];
        $b = $this->mine($request, $booking);
        abort_if(in_array($b->status, ['cancelled', 'expired', 'no_show'], true), 422);
        $me = (string) $request->user()?->getAuthIdentifier();
        $member = collect($this->team->roster())->firstWhere('id', $b->member_id);
        $items = (array) ($mine->section($member['identity_id'] ?? $me)['items'] ?? []);
        $due = 0;
        foreach ($items as $item) {
            if (is_array($item) && ($item['id'] ?? null) === $b->id) {
                $due = (int) ($item['due_cents'] ?? 0);
            }
        }
        if ($due < 1) {
            return back()->with('warning', __('Nothing is left to pay on this booking.'));
        }
        $name = collect($this->team->roster())->firstWhere('identity_id', $me)['name'] ?? 'Team';
        app(Deposits::class)->paidInPerson($b, $method, $due, $me, (string) $name);

        return back()->with('status', Money::format($due).' '.__('recorded:').' '.strtolower(InPersonPayments::METHODS[$method]).'.');
    }

    /** Uses the customer's full loyalty card (26.5) on what's left to pay — once. */
    public function reward(Request $request, string $booking, MyBookings $mine, LoyaltyCards $loyalty): RedirectResponse
    {
        $b = $this->mine($request, $booking);
        abort_if(in_array($b->status, ['cancelled', 'expired', 'no_show'], true) || (int) $b->reward_cents > 0, 422);
        $member = collect($this->team->roster())->firstWhere('id', $b->member_id);
        $item = collect((array) ($mine->section((string) ($member['identity_id'] ?? $request->user()?->getAuthIdentifier()))['items'] ?? []))->firstWhere('id', $b->id);
        $offer = $loyalty->offer($b->customer_phone, (int) ($item['due_cents'] ?? 0), 'booking');
        if ($offer === null || ! $loyalty->redeem($offer['reward_id'], 'booking', $b->id, $offer['discount_cents'])) {
            return back()->with('warning', __('There’s no reward to use on this booking.'));
        }
        $b->update(['reward_cents' => $offer['discount_cents']]);

        return back()->with('status', __('Reward used: :off off.', ['off' => Money::format($offer['discount_cents'])]));
    }

    /** A booking with this person — or any booking, for someone who manages bookings. Otherwise it isn't here. */
    private function mine(Request $request, string $id): Booking
    {
        $b = Booking::query()->whereKey($id)->firstOrFail();
        $me = (string) $request->user()?->getAuthIdentifier();
        $member = collect($this->team->roster())->firstWhere('id', $b->member_id);
        abort_unless(($member !== null && $member['identity_id'] === $me) || Gate::allows('bookings.manage'), 404);

        return $b;
    }
}
