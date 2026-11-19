<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Sales\Models\Sale;
use Wurk\Shifts\Support\Clock;
use Wurk\Tenancy\Contracts\TenantContext;

/*
 * Sprint 26.4: shifts, clock-in and commission. The roster is built from booking hours; leave and swaps are approved
 * by the owner; clocking in is open by default, or only on the business's Wi-Fi or within 200 m; and the month's
 * commission and tips per person export to a spreadsheet that matches the takings. Not payroll.
 */

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-05 08:00', 'Africa/Johannesburg')); // Wednesday
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->in = fn (callable $f) => app(TenantContext::class)->run($this->glow, $f);
    $this->owner = $this->person(['phone' => '+27831000000', 'email' => 'owner@glow.co.za']);
    $this->stylist = $this->person(['phone' => '+27831000002', 'email' => 'lindiwe@glow.co.za']);
    $this->other = $this->person(['phone' => '+27831000003', 'email' => 'naledi@glow.co.za']);
    foreach ([$this->owner, $this->stylist, $this->other] as $p) {
        $this->member($p, $this->glow);
    }
    [$this->lindiwe, $this->naledi] = ($this->in)(function () {
        app(Team::class)->add($this->owner->id, 'Zodwa', 'owner');
        $l = (string) app(Team::class)->add($this->stylist->id, 'Lindiwe', 'staff')->id;
        $n = (string) app(Team::class)->add($this->other->id, 'Naledi', 'staff')->id;
        foreach ([[0, '09:00', '17:00'], [2, '09:00', '13:00'], [5, '08:00', '12:00']] as [$day, $from, $to]) {
            BookingHours::query()->create(['member_id' => $l, 'weekday' => $day, 'starts' => $from, 'ends' => $to]);
        }

        return [$l, $n];
    });
    $this->roster = $this->app('/glow-salon/shifts');
    $this->mine = $this->app('/glow-salon/shifts/mine');
});

it('builds the week from booking hours, without doubling up, and shows each person their own shifts', function () {
    $this->actingAs($this->owner)->post($this->roster.'/fill', ['week' => '2027-05-03'])->assertSessionHas('status', '3 shifts added from booking hours.');
    $this->actingAs($this->owner)->post($this->roster.'/fill', ['week' => '2027-05-03'])->assertSessionHas('status', 'Nothing to add: everyone’s booking hours are already on the roster.');
    $this->actingAs($this->owner)->post($this->roster, ['member_id' => $this->naledi, 'on' => '2027-05-07', 'starts' => '10:00', 'ends' => '09:00'])->assertSessionHasErrors('ends');
    $this->actingAs($this->owner)->post($this->roster, ['member_id' => $this->naledi, 'on' => '2027-05-07', 'starts' => '10:00', 'ends' => '18:00'])->assertSessionHas('status', 'Shift added.');
    $this->actingAs($this->owner)->get($this->roster.'?week=2027-05-05')->assertInertia(fn (Assert $p) => $p->component('Shifts::Roster')->where('week.monday', '2027-05-03')->has('shifts', 4));

    $this->actingAs($this->stylist)->get($this->mine)->assertInertia(fn (Assert $p) => $p->component('Shifts::Mine')->has('shifts', 3)
        ->where('shifts.0.label', 'Mon 3 May')->where('shifts.1.starts', '09:00')->where('shifts.1.ends', '13:00'));
    $this->actingAs($this->stylist)->get($this->roster)->assertNotFound(); // the roster is the owner's
    // Today: my shift, with the clock button.
    $this->actingAs($this->stylist)->get($this->app('/glow-salon/today'))->assertInertia(fn (Assert $p) => $p->where('sections.0.key', 'shifts')
        ->where('sections.0.items.0.title', '09:00–13:00')->where('sections.0.items.0.actions.0.label', 'Clock in'));
});

it('lets the team ask for leave and swaps, which only count once the owner approves', function () {
    $this->actingAs($this->owner)->post($this->roster.'/fill', ['week' => '2027-05-03']);
    $this->actingAs($this->stylist)->post($this->mine.'/leave', ['from' => '2027-05-08', 'until' => '2027-05-08', 'note' => 'Family wedding'])->assertSessionHas('status', 'Asked. The owner will answer.');
    $saturday = ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('shifts')->where('on', '2027-05-08')->value('id'));
    $this->actingAs($this->stylist)->get($this->mine)->assertInertia(fn (Assert $p) => $p->has('shifts', 3)->where('requests.0.status', 'pending'));

    [$leave] = ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('shift_requests')->pluck('id')->all());
    $this->actingAs($this->owner)->post($this->roster.'/requests/'.$leave, ['approve' => true])->assertSessionHas('status', 'Approved.');
    $this->actingAs($this->stylist)->get($this->mine)->assertInertia(fn (Assert $p) => $p->has('shifts', 2)); // Saturday's shift cleared
    $this->actingAs($this->owner)->post($this->roster.'/fill', ['week' => '2027-05-03'])->assertSessionHas('status', 'Nothing to add: everyone’s booking hours are already on the roster.'); // leave is kept
    expect($saturday)->not->toBeNull();

    $friday = ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('shifts')->where('on', '2027-05-05')->value('id'));
    $this->actingAs($this->stylist)->post($this->mine.'/swap', ['shift_id' => $friday, 'swap_with' => $this->naledi])->assertSessionHasNoErrors();
    $swap = ($this->in)(fn () => DB::connection(app(TenantContext::class)->connectionName())->table('shift_requests')->where('kind', 'swap')->value('id'));
    $this->actingAs($this->owner)->post($this->roster.'/requests/'.$swap, ['approve' => true]);
    $this->actingAs($this->other)->get($this->mine)->assertInertia(fn (Assert $p) => $p->has('shifts', 1)->where('shifts.0.on', '2027-05-05'));
    $this->actingAs($this->owner)->post($this->roster.'/requests/'.$swap, ['approve' => false])->assertSessionHasErrors('request'); // answered already
});

it('clocks in and out — from anywhere by default, or only on the Wi-Fi or near the business', function () {
    $this->actingAs($this->stylist)->post($this->mine.'/clock-in')->assertSessionHas('status', 'Clocked in at 08:00.');
    $this->actingAs($this->stylist)->post($this->mine.'/clock-in')->assertSessionHasErrors(['clock' => 'You’re already clocked in.']);
    $this->travel(4)->hours();
    $this->travel(30)->minutes();
    $this->actingAs($this->stylist)->post($this->mine.'/clock-out')->assertSessionHas('status', 'Clocked out. You worked 4 h 30 min.');

    $this->actingAs($this->owner)->put($this->roster.'/clock', ['clock_check' => 'wifi', 'wifi' => []])->assertSessionHasErrors('clock_check');
    $this->actingAs($this->owner)->put($this->roster.'/clock', ['clock_check' => 'wifi', 'wifi' => ['196.25.1.10']])->assertSessionHas('status', 'Saved.');
    $this->actingAs($this->stylist)->withServerVariables(['REMOTE_ADDR' => '41.0.0.7'])->post($this->mine.'/clock-in')->assertSessionHasErrors(['clock' => 'Connect to the business’s Wi-Fi to clock in.']);
    $this->actingAs($this->stylist)->withServerVariables(['REMOTE_ADDR' => '196.25.1.10'])->post($this->mine.'/clock-in')->assertSessionHasNoErrors();
    $this->actingAs($this->stylist)->post($this->mine.'/clock-out');

    $this->actingAs($this->owner)->put($this->roster.'/clock', ['clock_check' => 'location', 'places' => [['name' => 'Salon', 'lat' => -26.1076, 'lng' => 28.0567]]]);
    $this->actingAs($this->stylist)->post($this->mine.'/clock-in', ['lat' => -26.1200, 'lng' => 28.0567])->assertSessionHasErrors(['clock' => 'You need to be at work to clock in.']); // ~1.4 km away
    $this->actingAs($this->stylist)->post($this->mine.'/clock-in', ['lat' => -26.1080, 'lng' => 28.0570])->assertSessionHasNoErrors(); // ~50 m
    expect(Clock::metres(-26.1076, 28.0567, -26.1080, 28.0570))->toBeLessThan(60.0);
});

it('works out each person’s commission and tips for the month, matching the takings, and exports it', function () {
    ($this->in)(function () {
        $braids = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $braids->id, 'member_id' => $this->lindiwe, 'tenant_id' => $this->glow->id]);
        foreach (['09:00', '11:00'] as $t) {
            app(Booker::class)->book($braids, CarbonImmutable::parse("2027-05-05 {$t}", 'Africa/Johannesburg'), ['name' => 'Customer'], $this->lindiwe, asOwner: true)->update(['status' => 'completed']);
        }
        app(Booker::class)->book($braids, CarbonImmutable::parse('2027-05-06 09:00', 'Africa/Johannesburg'), ['name' => 'Not done yet'], $this->lindiwe, asOwner: true);
        Sale::query()->forceCreate(['amount_cents' => 20000, 'method' => 'cash', 'status' => 'recorded', 'recorded_by' => $this->stylist->id, 'recorded_by_name' => 'Lindiwe', 'recorded_at' => now(), 'refunded_cents' => 5000]);
    });
    $earnings = $this->roster.'/earnings';
    $this->actingAs($this->owner)->put($this->roster.'/rates/'.$this->lindiwe, ['services' => '15', 'products' => '10'])->assertSessionHas('status', 'Commission saved.');
    $this->actingAs($this->owner)->post($this->roster.'/tips', ['member_id' => $this->lindiwe, 'amount' => '50'])->assertSessionHas('status', 'Tip recorded.');

    $this->actingAs($this->owner)->get($earnings)->assertInertia(fn (Assert $p) => $p->component('Shifts::Earnings')->where('month.label', 'May 2027')
        ->where('rows', fn ($rows) => collect($rows)->firstWhere('name', 'Lindiwe') === [...collect($rows)->firstWhere('name', 'Lindiwe'), 'services_cents' => 90000, 'products_cents' => 15000,
            'commission_cents' => 13500 + 1500, 'tips_cents' => 5000, 'earned_cents' => 20000])
        ->where('totals.services_cents', 90000)->where('totals.products_cents', 15000)); // = the completed bookings and the till's net sales

    $csv = (string) $this->actingAs($this->owner)->get($earnings.'/export?month=2027-05')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="team-earnings-2027-05.csv"')->getContent();
    expect($csv)->toContain('Lindiwe,0,900.00,15,150.00,10,150.00,50.00,200.00')->toContain('Not payroll');
    $this->actingAs($this->stylist)->get($earnings)->assertNotFound();
});
