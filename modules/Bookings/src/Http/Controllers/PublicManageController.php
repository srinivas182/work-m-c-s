<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * The customer's own page for a booking (a signed link, no account): see it, cancel it, or move it
 * to another free time. Plain HTML forms — works without JavaScript.
 */
final class PublicManageController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context) {}

    public function show(Request $request, string $handle, string $booking): Response
    {
        if (! $request->hasValidSignatureWhileIgnoring(['date'])) {
            abort(403, 'This link has expired or isn’t valid.');
        }

        return $this->within($handle, $booking, function (CurrentTenant $tenant, Booking $b) use ($request): Response {
            $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('date')) === 1 ? (string) $request->query('date') : null;
            $service = BookingService::query()->whereKey($b->service_id)->firstOrFail();
            $times = $date === null ? [] : array_map(fn (array $t) => ['starts_at' => $t['starts_at'], 'label' => CarbonImmutable::parse($t['starts_at'])->setTimezone($tenant->timezone())->format('H:i')],
                app(Availability::class)->on($service, $date, $b->member_id));

            return $this->page($tenant, $b, $service, ['date' => $date, 'times' => $times]);
        });
    }

    public function cancel(string $handle, string $booking): Response
    {
        return $this->within($handle, $booking, function (CurrentTenant $tenant, Booking $b): Response {
            if (! $this->changeable($b)) {
                return $this->page($tenant, $b, BookingService::query()->whereKey($b->service_id)->firstOrFail());
            }
            $refunded = app(CancellationPolicy::class)->customerCancels($b)['refunded'];

            return $this->page($tenant, $b->refresh(), BookingService::query()->whereKey($b->service_id)->firstOrFail(), ['notice' => $refunded
                ? 'Your booking is cancelled and your deposit is being refunded.' : 'Your booking is cancelled.']);
        });
    }

    public function move(Request $request, string $handle, string $booking): Response
    {
        return $this->within($handle, $booking, function (CurrentTenant $tenant, Booking $b) use ($request): Response {
            $service = BookingService::query()->whereKey($b->service_id)->firstOrFail();
            $startsAt = CarbonImmutable::parse((string) $request->input('starts_at'));
            $open = collect(app(Availability::class)->on($service, $startsAt->setTimezone($tenant->timezone())->format('Y-m-d'), $b->member_id))
                ->contains('starts_at', $startsAt->utc()->toIso8601String());
            if (! $this->changeable($b) || ! $open) {
                return $this->page($tenant, $b, $service, ['notice' => 'Sorry — that time isn’t available any more. Your booking hasn’t changed.']);
            }
            try {
                app(Booker::class)->move($b, $startsAt, null, null);
            } catch (SlotTaken) {
                return $this->page($tenant, $b->refresh(), $service, ['notice' => 'Sorry — that time has just been taken. Your booking hasn’t changed.']);
            }

            return $this->page($tenant, $b->refresh(), $service, ['notice' => 'Your booking has been moved.']);
        });
    }

    private function changeable(Booking $b): bool
    {
        return in_array($b->status, Booking::ACTIVE, true) && $b->starts_at->isFuture();
    }

    /** @param  array<string, mixed>  $extra */
    private function page(CurrentTenant $tenant, Booking $b, BookingService $service, array $extra = []): Response
    {
        $tz = $tenant->timezone();
        $policy = app(CancellationPolicy::class);

        return new Response(view('bookings::public.manage', [
            'business' => $tenant->displayName(), 'service' => $service->name, 'customer' => $b->customer_name,
            'when' => $b->starts_at->setTimezone($tz)->format('l j F, H:i'), 'status' => $b->status,
            'changeable' => $this->changeable($b), 'policy' => $policy->wording(),
            'deposit' => $b->paid_at !== null && $b->refunded_at === null ? ($policy->refundsNow($b) ? 'refund' : 'keep') : null,
            'action' => url()->current(),
            'signature' => ['expires' => (string) request()->query('expires'), 'signature' => (string) request()->query('signature')],
            'cancelUrl' => URL::temporarySignedRoute('bookings.public.manage.cancel', $b->starts_at, ['handle' => $tenant->handle(), 'booking' => $b->id]),
            'moveUrl' => URL::temporarySignedRoute('bookings.public.manage.move', $b->starts_at, ['handle' => $tenant->handle(), 'booking' => $b->id]),
            'online' => $b->online,
            'joinUrl' => $b->online && $b->meeting_url !== null && $b->status === 'confirmed' && now()->greaterThanOrEqualTo($b->starts_at->subMinutes(15)) && now()->lessThan($b->ends_at) ? $b->meeting_url : null,
            'date' => null, 'times' => [], 'notice' => null, ...$extra,
        ])->render(), 200, ['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
    }

    private function within(string $handle, string $booking, \Closure $fn): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, fn () => $fn($tenant, Booking::query()->whereKey($booking)->firstOrFail()));
    }
}
