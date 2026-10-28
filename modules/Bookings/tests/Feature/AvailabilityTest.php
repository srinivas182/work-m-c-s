<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Instant;
use Wurk\Bookings\Support\SlotTaken;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

const ZANELE = '01J00000000000000000000001';
const THABO = '01J00000000000000000000002';

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->monday = '2027-05-03'; // a Monday
    $this->now = CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg');
    $this->travelTo($this->now); // bookings are checked against “now” (notice, 60-day horizon)
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->service = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'buffer_after_minutes' => 15, 'price_cents' => 45000]);
        foreach ([ZANELE, THABO] as $member) {
            DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $member, 'tenant_id' => app(TenantContext::class)->id()]);
            BookingHours::query()->create(['member_id' => $member, 'weekday' => 0, 'starts' => '09:00', 'ends' => '12:00']); // lunch 12–13
            BookingHours::query()->create(['member_id' => $member, 'weekday' => 0, 'starts' => '13:00', 'ends' => '17:00']);
        }

        return $service;
    });
    $this->starts = fn (?string $member = null, ?CarbonImmutable $now = null) => ($this->in)(fn () => array_map(
        fn ($s) => CarbonImmutable::parse($s['starts_at'])->setTimezone('Africa/Johannesburg')->format('H:i'),
        app(Availability::class)->on($this->service, $this->monday, $member, $now ?? $this->now)));
    $this->at = fn (string $hm) => CarbonImmutable::parse("{$this->monday} {$hm}", 'Africa/Johannesburg');
});

it('offers times inside working hours, in the business’s time zone, with the buffer fitting before the break', function () {
    $starts = ($this->starts)(ZANELE);
    expect($starts[0])->toBe('09:00')
        ->and($starts)->toContain('10:45')->not->toContain('11:00')   // 10:45 + 60 + 15 = 12:00, the break
        ->toContain('13:00')->toContain('15:45')->not->toContain('16:00');
    $first = ($this->in)(fn () => app(Availability::class)->on($this->service, $this->monday, ZANELE, $this->now)[0]['starts_at']);
    expect($first)->toBe('2027-05-03T07:00:00+00:00'); // 09:00 in Johannesburg is 07:00 UTC
});

it('blocks a booking’s time (buffers included) and frees it on cancellation', function () {
    $booking = ($this->in)(fn () => app(Booker::class)->book($this->service, ($this->at)('09:00'), ['name' => 'Sipho'], ZANELE));
    $starts = ($this->starts)(ZANELE);
    expect($starts)->not->toContain('09:00')->not->toContain('10:00')->toContain('10:15'); // booked 09:00–10:00 + 15 min buffer

    ($this->in)(fn () => app(Booker::class)->cancel($booking, null));
    expect(($this->starts)(ZANELE))->toContain('09:00');
});

it('needs notice, and respects time off — one person’s, or the whole business’s', function () {
    expect(($this->starts)(ZANELE, ($this->at)('08:30')))->not->toContain('09:00')->toContain('09:30');

    ($this->in)(fn () => TimeOff::query()->create(['member_id' => ZANELE, 'starts_at' => Instant::db(($this->at)('13:00')), 'ends_at' => Instant::db(($this->at)('17:00'))]));
    expect(($this->starts)(ZANELE))->not->toContain('13:00')->and(($this->starts)(THABO))->toContain('13:00');

    ($this->in)(fn () => TimeOff::query()->create(['member_id' => null, 'starts_at' => Instant::db(($this->at)('00:00')), 'ends_at' => Instant::db(($this->at)('00:00')->addDay()), 'reason' => 'Public holiday']));
    expect(($this->starts)())->toBe([]);
});

it('books “anyone” across the team until everyone is busy at that time', function () {
    $book = fn () => ($this->in)(fn () => app(Booker::class)->book($this->service, ($this->at)('10:00'), ['name' => 'Customer']));
    $first = $book();
    $second = $book();
    expect([$first->member_id, $second->member_id])->toEqualCanonicalizing([ZANELE, THABO])
        ->and(fn () => $book())->toThrow(SlotTaken::class, 'that time has just been taken');
});

it('never double-books, even when two customers both saw the time as free (the database decides)', function () {
    ($this->in)(fn () => app(Booker::class)->book($this->service, ($this->at)('10:00'), ['name' => 'First'], ZANELE));

    // The second customer's page still showed 10:30 as free (it was loaded before the first booking).
    app()->instance(Availability::class, new readonly class(app(TenantContext::class)) extends Availability
    {
        public function on(BookingService $service, string $date, ?string $memberId = null, ?CarbonImmutable $now = null, ?string $branchId = null): array
        {
            return [['starts_at' => CarbonImmutable::parse('2027-05-03 10:30', 'Africa/Johannesburg')->utc()->toIso8601String(), 'members' => [ZANELE]]];
        }
    });
    expect(fn () => ($this->in)(fn () => app(Booker::class)->book($this->service, ($this->at)('10:30'), ['name' => 'Second'], ZANELE)))
        ->toThrow(SlotTaken::class);
    expect(($this->in)(fn () => Booking::query()->count()))->toBe(1);
});
