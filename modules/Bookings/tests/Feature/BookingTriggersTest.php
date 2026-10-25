<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Reminders;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->book = fn (string $at, string $email) => ($this->in)(function () use ($at, $email) {
        $service = BookingService::query()->firstOrCreate(['name' => 'Knotless braids'], ['duration_minutes' => 60]);
        $db = DB::connection(app(TenantContext::class)->connectionName());
        if ($db->table('booking_service_member')->count() === 0) {
            $db->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => '01J00000000000000000000001', 'tenant_id' => app(TenantContext::class)->id()]);
            BookingHours::query()->create(['member_id' => '01J00000000000000000000001', 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);
        }

        return app(Booker::class)->book($service, CarbonImmutable::parse($at, 'Africa/Johannesburg'), ['name' => 'Sipho', 'email' => $email]);
    });
    $this->subjects = fn (string $to) => Mail::sent(BookingMessageMail::class, fn ($m) => $m->hasTo($to))->map(fn ($m) => $m->subjectLine)->all();
});

it('confirms a new booking, and sends the reminder 24 hours before — once', function () {
    ($this->book)('2027-05-03 09:00', 'sipho@example.com');
    expect(($this->subjects)('sipho@example.com'))->toBe(['Booking confirmed — Glow Salon']);

    $this->travelTo(CarbonImmutable::parse('2027-05-02 08:00', 'Africa/Johannesburg'));
    expect(app(Reminders::class)->sendDue())->toBe(0); // not yet
    $this->travelTo(CarbonImmutable::parse('2027-05-02 09:01', 'Africa/Johannesburg'));
    expect(app(Reminders::class)->sendDue())->toBe(1)->and(app(Reminders::class)->sendDue())->toBe(0);
    expect(($this->subjects)('sipho@example.com'))->toBe(['Booking confirmed — Glow Salon', 'Reminder: your booking at Glow Salon']);
});

it('tells the customer when the booking moves or is cancelled — and a cancelled booking gets no reminder', function () {
    $booking = ($this->book)('2027-05-03 09:00', 'lerato@example.com');
    ($this->in)(fn () => app(Booker::class)->move($booking, CarbonImmutable::parse('2027-05-03 11:00', 'Africa/Johannesburg'), null, null));
    ($this->in)(fn () => app(Booker::class)->cancel($booking->refresh(), null));
    expect(($this->subjects)('lerato@example.com'))->toBe(['Booking confirmed — Glow Salon', 'Your booking has moved — Glow Salon', 'Booking cancelled — Glow Salon']);

    $this->travelTo(CarbonImmutable::parse('2027-05-02 12:00', 'Africa/Johannesburg'));
    expect(app(Reminders::class)->sendDue())->toBe(0);
});
