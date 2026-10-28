<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\BranchServices;
use Wurk\Bookings\Support\Deposits;
use Wurk\Tenancy\Contracts\BranchDirectory;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class, FakesYoco::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg')); // a Saturday
    $this->fakeYoco();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->in = fn (callable $fn) => app(TenantContext::class)->run($this->glow, $fn);
    [$this->main, $this->sandton, $this->zanele, $this->braids] = ($this->in)(function () {
        $main = app(BranchDirectory::class)->main();
        $sandton = app(BranchDirectory::class)->add('Sandton', '12 Rivonia Rd', null);
        $zanele = app(Team::class)->add((string) Str::ulid(), 'Zanele', 'staff')->id;
        $braids = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000, 'deposit_type' => 'percent', 'deposit_value' => 20]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $braids->id, 'member_id' => $zanele, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => $zanele, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00', 'branch_id' => $main]);    // Mondays at the main branch
        BookingHours::query()->create(['member_id' => $zanele, 'weekday' => 1, 'starts' => '09:00', 'ends' => '17:00', 'branch_id' => $sandton]); // Tuesdays in Sandton

        return [$main, $sandton, $zanele, $braids];
    });
    $this->free = fn (string $date, string $branch) => ($this->in)(fn () => count(app(Availability::class)->on($this->braids, $date, null, null, $branch)));
});

it('offers each person’s hours at the branch where they work', function () {
    expect(($this->free)('2027-05-03', $this->main))->toBeGreaterThan(0)->and(($this->free)('2027-05-03', $this->sandton))->toBe(0)   // Monday
        ->and(($this->free)('2027-05-04', $this->sandton))->toBeGreaterThan(0)->and(($this->free)('2027-05-04', $this->main))->toBe(0); // Tuesday
});

it('offers a service only at its branches, books at a branch, and uses the branch’s price', function () {
    ($this->in)(fn () => app(BranchServices::class)->set($this->braids, [$this->main => null]));
    expect(($this->free)('2027-05-04', $this->sandton))->toBe(0); // not offered in Sandton

    ($this->in)(fn () => app(BranchServices::class)->set($this->braids, [$this->main => null, $this->sandton => 50000])); // R500 in Sandton
    $booking = ($this->in)(fn () => app(Booker::class)->book($this->braids, CarbonImmutable::parse('2027-05-04 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho'], branchId: $this->sandton, source: 'website'));
    expect($booking->branch_id)->toBe($this->sandton);
    $this->connectYoco($this->glow);
    ($this->in)(fn () => expect(app(Deposits::class)->amountFor($this->braids, branchId: $this->sandton))->toBe(10000)          // 20% of R500
        ->and(app(Deposits::class)->amountFor($this->braids, branchId: $this->main))->toBe(9000));                                 // 20% of R450
});

it('shows a branch manager only their branches’ bookings', function () {
    ($this->in)(function () {
        app(Booker::class)->book($this->braids, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Lerato'], source: 'website', branchId: $this->main);
        app(Booker::class)->book($this->braids, CarbonImmutable::parse('2027-05-04 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho'], source: 'website', branchId: $this->sandton);
    });
    $manager = $this->person(['email' => 'manager@glow.co.za']);
    ($this->in)(function () use ($manager) {
        $m = app(Team::class)->add($manager->id, 'Musa', 'branch-manager');
        app(Team::class)->setBranches($m, [$this->sandton]);
    });
    $this->actingAs($manager);
    expect(($this->in)(fn () => Booking::query()->pluck('customer_name')->all()))->toBe(['Sipho']);
});
