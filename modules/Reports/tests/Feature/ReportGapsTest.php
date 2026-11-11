<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-20 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $p = app(PackageCatalog::class)->create([
        'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings', 'referrals'],
    ], null);
    app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->member($this->owner, $this->glow);
    $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Owner', 'owner'));
    $this->report = fn (string $q) => $this->actingAs($this->owner)->get($this->app('/glow-salon/reports?'.$q));
});

it('reports any chosen dates — the previous period the same length — and falls back when the range makes no sense', function () {
    ($this->report)('period=custom&from=2027-05-03&to=2027-05-09')->assertInertia(fn (Assert $p) => $p->where('period', 'custom')->where('range', ['from' => '2027-05-03', 'to' => '2027-05-09']));
    ($this->report)('period=custom&from=2027-05-09&to=2027-05-03')->assertInertia(fn (Assert $p) => $p->where('range.from', '2027-05-01'));   // reversed: this month
    ($this->report)('period=custom&from=nonsense&to=2027-05-03')->assertInertia(fn (Assert $p) => $p->where('range.from', '2027-05-01'));
});

it('shows bookings utilisation — working time less time off, booked time incl. no-shows — and links to the referral reports', function () {
    $this->ctx->run($this->glow, function () {
        $db = DB::connection($this->ctx->connectionName());
        $t = $this->ctx->id();
        $member = (string) Str::ulid();
        $service = (string) Str::ulid();
        $db->table('booking_services')->insert(['id' => $service, 'tenant_id' => $t, 'name' => 'Braids', 'duration_minutes' => 60, 'price_cents' => 20000, 'created_at' => now(), 'updated_at' => now()]);
        $db->table('booking_hours')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'member_id' => $member, 'weekday' => 0, 'starts' => '09:00:00', 'ends' => '17:00:00']); // Mondays
        $at = fn (string $time) => CarbonImmutable::parse('2027-05-17 '.$time, 'Africa/Johannesburg');                                // a Monday
        $db->table('booking_time_off')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'member_id' => $member, 'starts_at' => $at('12:00'), 'ends_at' => $at('14:00'), 'reason' => 'Lunch out']);
        foreach ([['09:00', 'completed'], ['10:00', 'completed'], ['15:00', 'no_show'], ['16:00', 'cancelled']] as [$time, $status]) {
            $db->table('bookings')->insert(['id' => (string) Str::ulid(), 'tenant_id' => $t, 'service_id' => $service, 'member_id' => $member, 'starts_at' => $at($time),
                'ends_at' => $at($time)->addHour(), 'status' => $status, 'customer_name' => 'C', 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    $sections = collect(($this->report)('period=custom&from=2027-05-17&to=2027-05-17')->viewData('page')['props']['sections']);
    expect(collect($sections->firstWhere('key', 'bookings')['metrics'])->firstWhere('label', 'Utilisation (booked ÷ working time)')['value'])->toBe(50.0) // 3h of 6h
        ->and($sections->firstWhere('key', 'referrals')['link'])->toBe(['label' => 'Full referral reports', 'href' => '/glow-salon/referral-reports']);
});
