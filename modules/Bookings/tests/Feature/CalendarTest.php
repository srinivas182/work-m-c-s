<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
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
