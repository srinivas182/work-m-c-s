<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Events\BookingConfirmed;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Crm\Contracts\Takings;
use Wurk\Payments\Contracts\InPersonPayments;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-03 10:00', 'Africa/Johannesburg')); // a Monday
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $p = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    [$this->service, $this->zanele] = $this->ctx->run($this->glow, function () {
        $zanele = app(Team::class)->add($this->owner->id, 'Zanele', 'owner');
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $zanele->id, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => $zanele->id, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);

        return [$service, $zanele];
    });
    $this->base = $this->app('/glow-salon/bookings');
    $this->add = fn (string $time, string $name = 'Walk-in') => $this->actingAs($this->owner)->post($this->base, [
        'service' => $this->service->id, 'member' => $this->zanele->id, 'date' => '2027-05-03', 'time' => $time, 'name' => $name,
    ]);
    $this->local = fn (Booking $b) => $b->starts_at->setTimezone('Africa/Johannesburg')->format('H:i');
});

it('lets the owner add a walk-in right now, and refuses a clash', function () {
    ($this->add)('10:00')->assertSessionHas('status', 'Booking added.'); // customers would need an hour's notice
    ($this->add)('10:30', 'Lerato')->assertSessionHasErrors(['time' => 'That time clashes with another booking. Choose another time or team member.']);

    $this->actingAs($this->owner)->get("{$this->base}?date=2027-05-03")->assertOk()->assertInertia(fn (Assert $p) => $p->component('Bookings::Calendar')
        ->where('view', 'day')->has('bookings', 1)->where('bookings.0.customer', 'Walk-in')->where('bookings.0.starts_at', '2027-05-03T10:00:00+02:00'));
});

it('moves a booking, and leaves it untouched when the new time clashes', function () {
    ($this->add)('11:00', 'Sipho');
    ($this->add)('14:00', 'Lerato');
    [$sipho, $lerato] = $this->ctx->run($this->glow, fn () => [Booking::query()->where('customer_name', 'Sipho')->sole(), Booking::query()->where('customer_name', 'Lerato')->sole()]);

    $this->actingAs($this->owner)->put("{$this->base}/{$sipho->id}/move", ['date' => '2027-05-03', 'time' => '14:30'])
        ->assertSessionHasErrors(['time' => 'That time clashes with another booking. The booking hasn’t moved.']);
    $this->ctx->run($this->glow, fn () => expect(($this->local)($sipho->refresh()))->toBe('11:00'));

    $this->actingAs($this->owner)->put("{$this->base}/{$sipho->id}/move", ['date' => '2027-05-03', 'time' => '12:00'])->assertSessionHas('status', 'Booking moved.');
    $this->ctx->run($this->glow, fn () => expect(($this->local)($sipho->refresh()))->toBe('12:00'));
    ($this->add)('11:00', 'Thandi')->assertSessionHas('status', 'Booking added.'); // the old time is free again
});

it('frees the time when a booking is cancelled', function () {
    ($this->add)('15:00', 'Sipho');
    $id = $this->ctx->run($this->glow, fn () => Booking::query()->sole()->id);
    $this->actingAs($this->owner)->post("{$this->base}/{$id}/status", ['status' => 'cancelled'])->assertSessionHas('status', 'Booking cancelled.');
    ($this->add)('15:00', 'Lerato')->assertSessionHas('status', 'Booking added.');
});

it('records money taken in person — confirming a booking that awaited its deposit — and counts it in the takings (Sprint 22.3)', function () {
    Event::fake([BookingConfirmed::class]);
    $this->ctx->run($this->glow, fn () => $this->service->update(['price_cents' => 45000]));
    ($this->add)('11:00', 'Lerato');
    $booking = $this->ctx->run($this->glow, function () {
        $b = Booking::query()->sole();
        $b->update(['status' => 'pending_payment', 'deposit_cents' => 10000, 'payment_reference' => 'dep_x']);

        return $b;
    });

    $this->actingAs($this->owner)->post("{$this->base}/{$booking->id}/paid", ['method' => 'cheque', 'amount' => '100'])->assertSessionHasErrors('method');
    $this->actingAs($this->owner)->post("{$this->base}/{$booking->id}/paid", ['method' => 'cash', 'amount' => '0'])->assertSessionHasErrors('amount');
    $this->actingAs($this->owner)->post("{$this->base}/{$booking->id}/paid", ['method' => 'cash', 'amount' => '100'])->assertSessionHas('status', 'R100.00 recorded: cash.');
    $this->ctx->run($this->glow, fn () => expect($booking->refresh()->status)->toBe('confirmed'));
    Event::assertDispatched(BookingConfirmed::class);

    // The rest at the end of the appointment, by card machine.
    $this->actingAs($this->owner)->post("{$this->base}/{$booking->id}/paid", ['method' => 'card_machine', 'amount' => '350'])->assertSessionHas('status', 'R350.00 recorded: card machine.');
    $this->actingAs($this->owner)->get("{$this->base}?date=2027-05-03")->assertInertia(fn (Assert $p) => $p
        ->where('bookings.0.price_cents', 45000)->where('bookings.0.in_person_cents', 45000)->where('bookings.0.deposit_paid', false));
    $this->ctx->run($this->glow, function () {
        expect(app(InPersonPayments::class)->totals(now()->startOfDay(), now()->endOfDay()))->toEqual(['cash' => 10000, 'card_machine' => 35000])
            ->and(app(Takings::class)->net(now()->subDay(), now()->addDay(), null))->toBe(45000)
            ->and(DB::connection(app(TenantContext::class)->connectionName())->table('audit_events')->where('action', 'payments.taken_in_person')->count())->toBe(2);
    });
});

it('lets only people who manage bookings record a payment, and never on a cancelled booking', function () {
    ($this->add)('11:00', 'Lerato');
    $booking = $this->ctx->run($this->glow, fn () => Booking::query()->sole());
    $viewer = $this->person(['first_name' => 'Sipho', 'email' => 'sipho@glow.co.za', 'phone' => '+27825550101']);
    $this->member($viewer, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($viewer->id, 'Sipho', 'staff'));
    expect($this->actingAs($viewer)->post("{$this->base}/{$booking->id}/paid", ['method' => 'cash', 'amount' => '100'])->status())->toBeIn([403, 404]);
    $this->ctx->run($this->glow, fn () => expect(app(InPersonPayments::class)->takenFor('booking', $booking->id))->toBe(0));
    $this->ctx->run($this->glow, fn () => $booking->update(['status' => 'cancelled']));
    $this->actingAs($this->owner)->post("{$this->base}/{$booking->id}/paid", ['method' => 'cash', 'amount' => '100'])->assertStatus(422);
});
