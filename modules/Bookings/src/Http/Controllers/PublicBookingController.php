<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Wurk\Access\Contracts\TeamDirectory;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Online booking from a business's own website ({handle}.{root}/book/…): what can be booked, free
 * times, and making a booking. Same-site JSON (the site's content policy allows only its own address);
 * no session or CSRF — a hidden spam field and rate limits, as on the quote form.
 */
final class PublicBookingController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Entitlements $entitlements) {}

    public function services(string $handle, TeamDirectory $team): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($team): JsonResponse {
            $names = array_column($team->members(), 'name', 'id');
            $pivot = DB::connection($this->context->connectionName())->table('booking_service_member');

            return new JsonResponse([
                'timezone' => $tenant->timezone(),
                'services' => BookingService::query()->where('active', true)->orderBy('position')->orderBy('name')->get()->map(fn (BookingService $s) => [
                    'id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'duration' => $s->duration_minutes, 'price_cents' => $s->price_cents, 'location' => $s->location_type,
                    'members' => $pivot->clone()->where('service_id', $s->id)->pluck('member_id')
                        ->filter(fn ($id) => isset($names[(string) $id]))->map(fn ($id) => ['id' => (string) $id, 'name' => $names[(string) $id]])->values()->all(),
                ])->all(),
            ]);
        });
    }

    public function times(Request $request, string $handle, Availability $availability): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $availability): JsonResponse {
            $data = $request->validate(['service' => ['required', 'string'], 'date' => ['required', 'date_format:Y-m-d'], 'member' => ['nullable', 'string']]);
            $service = BookingService::query()->where('active', true)->whereKey($data['service'])->firstOrFail();

            return new JsonResponse(['date' => $data['date'], 'times' => array_map(fn (array $t) => [
                'starts_at' => $t['starts_at'],
                'label' => CarbonImmutable::parse($t['starts_at'])->setTimezone($tenant->timezone())->format('H:i'),
            ], $availability->on($service, $data['date'], $data['member'] ?? null))]);
        });
    }

    public function book(Request $request, string $handle, Booker $booker, Deposits $deposits): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $booker, $deposits): JsonResponse {
            $data = $request->validate([
                'service' => ['required', 'string'],
                'starts_at' => ['required', 'date'],
                'member' => ['nullable', 'string'],
                'name' => ['required', 'string', 'max:120'],
                'phone' => ['required', 'string', 'max:40'],
                'email' => ['nullable', 'email', 'max:160'],
                'notes' => ['nullable', 'string', 'max:1000'],
                'online' => ['nullable', 'boolean'],
                'consent' => ['accepted'],
                'website' => ['nullable', 'string'], // hidden from people: only bots fill it in
            ], ['consent.accepted' => 'Please agree to be contacted about your booking.']);
            if (($data['website'] ?? '') !== '') {
                return new JsonResponse(['booked' => true], 201); // a bot: no signal, no booking
            }
            $service = BookingService::query()->where('active', true)->whereKey($data['service'])->firstOrFail();
            $online = $service->location_type === 'online' || ($service->location_type === 'either' && ($data['online'] ?? false));
            $deposit = $deposits->amountFor($service, $online);
            if ($deposit > 0 && empty($data['email'])) { // Paystack sends the receipt by email
                throw ValidationException::withMessages(['email' => 'Please add your email — a deposit is needed for this service, and the receipt is emailed.']);
            }
            try {
                $booking = $booker->book($service, CarbonImmutable::parse($data['starts_at']), [
                    'name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null,
                ], $data['member'] ?? null, source: 'website', status: $deposit > 0 ? 'pending_payment' : 'confirmed', online: $online);
            } catch (SlotTaken $e) {
                return new JsonResponse(['message' => $e->getMessage()], 409);
            }
            if ($deposit > 0) {
                $returnUrl = $request->getSchemeAndHttpHost().'/book/return';
                $payUrl = $deposits->start($booking, $deposit, (string) $data['email'], $returnUrl);
                if ($payUrl === null) {
                    $booker->cancel($booking, null, 'expired'); // payments unavailable: don't hold the time

                    return new JsonResponse(['message' => 'Online payment isn’t available right now. Please call or message us to book.'], 503);
                }

                return new JsonResponse(['pay_url' => $payUrl, 'deposit_cents' => $deposit], 201);
            }

            return new JsonResponse(['booked' => true, 'service' => $service->name,
                'when' => $booking->starts_at->setTimezone($tenant->timezone())->format('l j F, H:i'),
                'manage_url' => ManageLink::for($booking, $tenant->handle())], 201);
        });
    }

    /** Paystack sends the customer back here after paying the deposit. */
    public function paid(Request $request, string $handle, Deposits $deposits): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);
        $booking = $deposits->confirm((string) $request->query('reference', ''));
        $confirmed = $booking !== null && $booking->status === 'confirmed';
        // A booking or a place in a class: the class's time and link come from its session.
        $when = $booking === null ? '' : $this->context->run($tenant, fn () => ($booking instanceof ClassAttendee ? $booking->session()->starts_at : $booking->starts_at)
            ->setTimezone($tenant->timezone())->format('l j F, H:i'));
        $manage = $confirmed ? $this->context->run($tenant, fn () => $booking instanceof ClassAttendee
            ? ManageLink::forClass($booking, $tenant->handle()) : ManageLink::for($booking, $tenant->handle())) : null;

        return new Response(view('bookings::public.paid', ['business' => $tenant->displayName(), 'confirmed' => $confirmed, 'when' => $when, 'manage' => $manage])->render(), $confirmed ? 200 : 202);
    }

    /** Only for an active business whose package includes bookings. */
    private function within(string $handle, \Closure $fn): JsonResponse
    {
        $tenant = $this->tenants->findByHandle($handle);
        if ($tenant === null || ! $tenant->isActive() || ! $this->entitlements->entitled($tenant->tenantId(), 'bookings')) {
            abort(404);
        }

        return $this->context->run($tenant, fn () => $fn($tenant));
    }
}
