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
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
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

it('lets a branch manager run their branch — hours there, not elsewhere — but not change business-wide settings', function () {
    $p = app(PackageCatalog::class)->create([
        'key' => 'pro', 'name' => 'Pro', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 99900, 'yearly_price_cents' => 999000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 10,
        'multi_branch' => true, 'max_branches' => 5, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $owner = $this->person();
    $manager = $this->person(['email' => 'musa@glow.co.za', 'phone' => '+27820000002']);
    foreach ([$owner, $manager] as $who) {
        $this->member($who, $this->glow);
    }
    ($this->in)(function () use ($owner, $manager) {
        app(Team::class)->add($owner->id, 'Thandi', 'owner');
        app(Team::class)->setBranches(app(Team::class)->add($manager->id, 'Musa', 'branch-manager'), [$this->sandton]);
    });
    $base = $this->app('/glow-salon/bookings');
    $hours = fn (string $branch, string $starts = '09:00') => ['branch' => $branch, 'hours' => [['weekday' => 2, 'starts' => $starts, 'ends' => '12:00']]];

    $this->actingAs($manager)->put("{$base}/hours/{$this->zanele}", $hours($this->sandton))->assertSessionHas('status', 'Hours saved.');
    $this->actingAs($manager)->put("{$base}/hours/{$this->zanele}", $hours($this->main))->assertForbidden();
    $this->actingAs($manager)->put("{$base}/services/{$this->braids->id}", ['name' => 'Cheaper braids'])->assertNotFound(); // business-wide (inside a business, a refused permission is a 404 by design)

    // A person can't work two branches at once (Wednesday 09:00–12:00 is already Sandton).
    $this->actingAs($owner)->put("{$base}/hours/{$this->zanele}", $hours($this->main, '11:00'))
        ->assertSessionHasErrors(['hours.0.starts' => 'These hours overlap hours at another branch.']);

    $this->actingAs($owner)->put("{$base}/services/{$this->braids->id}", ['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000, 'location_type' => 'in_person',
        'deposit_type' => 'percent', 'deposit_value' => 20, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'members' => [$this->zanele],
        'branches' => [['branch' => $this->main, 'price_cents' => null], ['branch' => $this->sandton, 'price_cents' => 50000]]])->assertSessionHas('status', 'Service saved.');
    ($this->in)(fn () => expect(app(BranchServices::class)->priceAt($this->braids, $this->sandton))->toBe(50000));
});
