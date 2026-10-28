<?php

declare(strict_types=1);

namespace Wurk\Bookings\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Wurk\Bookings\Models\ClassAttendee;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Support\ClassBooker;
use Wurk\Bookings\Support\ClassFull;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\HealthPractice;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\CurrentTenant;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Group classes on the business's own website, and each attendee's signed page. */
final class PublicClassController
{
    public function __construct(private TenantDirectory $tenants, private TenantContext $context, private Entitlements $entitlements) {}

    public function index(string $handle): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant): JsonResponse {
            $tz = $tenant->timezone();
            $health = app(HealthPractice::class);
            $sessions = ClassSession::query()->where('status', 'scheduled')->where('starts_at', '>', Instant::db(now()))
                ->where('starts_at', '<', Instant::db(now()->addDays(14)))->orderBy('starts_at')->limit(60)->get();

            return new JsonResponse(['health' => $health->on() ? ['consent' => HealthPractice::CONSENT, 'version' => HealthPractice::CONSENT_VERSION] : null, 'classes' => $sessions->map(fn (ClassSession $s) => [
                'id' => $s->id, 'title' => $s->title, 'description' => $s->description, 'online' => $s->location_type === 'online',
                'when' => $s->starts_at->setTimezone($tz)->format('D j M, H:i'), 'minutes' => (int) $s->starts_at->diffInMinutes($s->ends_at),
                'price_cents' => $s->price_cents, 'places_left' => max(0, $s->capacity - $s->taken()),
                'registration' => $health->on() && $s->location_type === 'online' ? $health->registration($s->member_id) : null,
            ])->values()->all()]);
        });
    }

    public function attend(Request $request, string $handle, string $session, ClassBooker $booker, Deposits $deposits): JsonResponse
    {
        return $this->within($handle, function (CurrentTenant $tenant) use ($request, $session, $booker, $deposits): JsonResponse {
            $data = $request->validate([
                'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:40'], 'email' => ['nullable', 'email', 'max:160'],
                'consent' => ['accepted'], 'health_consent' => ['nullable', 'boolean'], 'website' => ['nullable', 'string'],
            ], ['consent.accepted' => 'Please agree to be contacted about your class.']);
            if (($data['website'] ?? '') !== '') {
                return new JsonResponse(['booked' => true], 201);
            }
            $class = ClassSession::query()->whereKey($session)->firstOrFail();
            $consented = $class->location_type === 'online' && app(HealthPractice::class)->on();
            if ($consented && ! ($data['health_consent'] ?? false)) {
                throw ValidationException::withMessages(['health_consent' => 'Please read and agree to the video-consultation terms to book online.']);
            }
            $price = $deposits->amountForClass($class);
            if ($price > 0 && empty($data['email'])) { // the payment receipt is emailed
                throw ValidationException::withMessages(['email' => 'Please add your email — this class is paid when booking, and the receipt is emailed.']);
            }
            try {
                $a = $booker->attend($class, ['name' => $data['name'], 'phone' => $data['phone'], 'email' => $data['email'] ?? null], 'website', $price > 0 ? 'pending_payment' : 'confirmed');
            } catch (ClassFull $e) {
                return new JsonResponse(['message' => $e->getMessage()], 409);
            }
            if ($consented) {
                $a->update(['consent_version' => HealthPractice::CONSENT_VERSION, 'consent_at' => now()]);
            }
            if ($price > 0) {
                $payUrl = $deposits->start($a, $price, (string) $data['email'], $request->getSchemeAndHttpHost().'/book/return');
                if ($payUrl === null) {
                    $a->update(['status' => 'expired']); // payments unavailable: don't hold the place

                    return new JsonResponse(['message' => 'Online payment isn’t available right now. Please call or message us to book.'], 503);
                }

                return new JsonResponse(['pay_url' => $payUrl, 'deposit_cents' => $price], 201);
            }

            return new JsonResponse(['booked' => true, 'service' => 'Class: '.$class->title,
                'when' => $class->starts_at->setTimezone($tenant->timezone())->format('l j F, H:i'), 'manage_url' => ManageLink::forClass($a, $tenant->handle())], 201);
        });
    }

    public function show(string $handle, string $attendee): Response
    {
        return $this->page($handle, $attendee, null);
    }

    public function leave(string $handle, string $attendee, ClassBooker $booker): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);
        $this->context->run($tenant, function () use ($attendee, $booker): void {
            $a = ClassAttendee::query()->whereKey($attendee)->firstOrFail();
            if ($a->status === 'confirmed' && $a->session()->starts_at->isFuture()) {
                $booker->leave($a, null);
            }
        });

        return $this->page($handle, $attendee, 'Your place is cancelled.');
    }

    private function page(string $handle, string $attendee, ?string $notice): Response
    {
        $tenant = $this->tenants->findByHandle($handle) ?? abort(404);

        return $this->context->run($tenant, function () use ($tenant, $attendee, $notice): Response {
            $a = ClassAttendee::query()->whereKey($attendee)->firstOrFail();
            $s = $a->session();
            $live = $s->status === 'scheduled' && $a->status === 'confirmed';
            $html = view('bookings::public.class', [
                'business' => $tenant->displayName(), 'title' => $s->title, 'customer' => $a->customer_name,
                'when' => $s->starts_at->setTimezone($tenant->timezone())->format('l j F, H:i'), 'notice' => $notice,
                'status' => $s->status === 'cancelled' ? 'class_cancelled' : $a->status, 'changeable' => $live && $s->starts_at->isFuture(),
                'online' => $s->location_type === 'online' && $live,
                'joinUrl' => $s->location_type === 'online' && $live && $s->meeting_url !== null && now()->greaterThanOrEqualTo($s->starts_at->subMinutes(15)) && now()->lessThan($s->ends_at) ? $s->meeting_url : null,
                'cancelUrl' => URL::temporarySignedRoute('bookings.public.class.leave', $s->starts_at, ['handle' => $tenant->handle(), 'attendee' => $a->id]),
            ])->render();

            return new Response($html, 200, ['X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
        });
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
