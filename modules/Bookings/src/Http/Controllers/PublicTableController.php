<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Wurk\Bookings\Models\Reservation;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Bookings\Support\Reserver;
use Wurk\Bookings\Support\TableAvailability;
use Wurk\Bookings\Support\TableFull;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Table reservations on the business's own website, and the customer's signed reservation page. */
final class PublicTableController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Entitlements $entitlements) {}

    public function times(Request $request, string $handle, TableAvailability $availability): JsonResponse
    {
        return $this->within($handle, function () use ($request, $availability): JsonResponse {
            $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'party' => ['required', 'integer', 'min:1', 'max:50']]);
            $settings = ReservationSettings::query()->find($this->context->id());

            return new JsonResponse(['max_party' => $settings?->max_party, 'times' => $availability->on($data['date'], (int) $data['party'])]);
        });
    }

    public function reserve(Request $request, string $handle, Reserver $reserver): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $reserver): JsonResponse {
            $data = $request->validate([
                'starts_at' => ['required', 'date'], 'party' => ['required', 'integer', 'min:1', 'max:50'],
                'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:160'], 'notes' => ['nullable', 'string', 'max:1000'],
                'consent' => ['accepted'], 'website' => ['nullable', 'string'],
            ], ['consent.accepted' => 'Please agree to be contacted about your reservation.']);
            if (($data['website'] ?? '') !== '') {
                return new JsonResponse(['booked' => true], 201); // a bot: no signal, no reservation
            }
            try {
                $r = $reserver->reserve(CarbonImmutable::parse($data['starts_at']), (int) $data['party'],
                    ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null], 'website');
            } catch (TableFull $e) {
                return new JsonResponse(['message' => $e->getMessage()], 409);
            }

            return new JsonResponse(['booked' => true, 'service' => 'Table for '.$r->party_size,
                'when' => $r->starts_at->setTimezone($tenant->timezone())->format('l j F, H:i'), 'manage_url' => ManageLink::forTable($r, $tenant->handle())], 201);
        });
    }

    public function show(string $handle, string $reservation): Response
    {
        return $this->page($handle, $reservation, null);
    }

    public function cancel(string $handle, string $reservation, Reserver $reserver): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);
        $this->context->run($tenant, function () use ($reservation, $reserver): void {
            $r = Reservation::query()->whereKey($reservation)->firstOrFail();
            if (in_array($r->status, Reservation::ACTIVE, true) && $r->starts_at->isFuture()) {
                $reserver->setStatus($r, 'cancelled', null);
            }
        });

        return $this->page($handle, $reservation, 'Your reservation is cancelled.');
    }

    private function page(string $handle, string $reservation, ?string $notice): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($tenant, $reservation, $notice): Response {
            $r = Reservation::query()->whereKey($reservation)->firstOrFail();
            $html = view('bookings::public.table', [
                'business' => $tenant->displayName(), 'party' => $r->party_size, 'customer' => $r->customer_name,
                'when' => $r->starts_at->setTimezone($tenant->timezone())->format('l j F, H:i'), 'status' => $r->status, 'notice' => $notice,
                'changeable' => $r->status === 'confirmed' && $r->starts_at->isFuture(),
                'cancelUrl' => URL::temporarySignedRoute('bookings.public.table.cancel', $r->starts_at, ['handle' => $tenant->handle(), 'reservation' => $r->id]),
            ])->render();

            return new Response($html, 200, ['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
        });
    }

    /** Only for an active business whose package includes bookings and which takes reservations. */
    private function within(string $handle, \Closure $fn): JsonResponse
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive() || ! $this->entitlements->entitled($tenant->tenantId(), 'bookings')) {
            abort(404);
        }

        return $this->context->run($tenant, function () use ($fn, $tenant): JsonResponse {
            if (ReservationSettings::query()->find($this->context->id())?->enabled !== true) {
                abort(404);
            }

            return $fn($tenant);
        });
    }
}
