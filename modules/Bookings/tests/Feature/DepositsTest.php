<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\BookingHold;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\Deposits;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class, FakesYoco::class);

const STYLIST = '01J00000000000000000000001';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    $this->fakeYoco();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    $this->service = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000, 'deposit_type' => 'fixed', 'deposit_value' => 10000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => STYLIST, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => STYLIST, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);

        return $service;
    });
    $this->hold = function () {
        $this->connectYoco($this->glow);

        return ($this->in)(function () {
            $booking = app(Booker::class)->book($this->service, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho', 'email' => 'sipho@example.com'], STYLIST, 'website', status: 'pending_payment');
            $url = app(Deposits::class)->start($booking, app(Deposits::class)->amountFor($this->service), 'sipho@example.com', 'https://glow.example/book/return');

            return [$booking->refresh(), $url];
        });
    };
    $this->free = fn () => ($this->in)(fn () => collect(app(Availability::class)->on($this->service, '2027-05-03'))->pluck('starts_at')->contains('2027-05-03T07:00:00+00:00'));
});

it('asks for a deposit only once card payments are connected — fixed or a share of the price', function () {
    ($this->in)(fn () => expect(app(Deposits::class)->amountFor($this->service))->toBe(0));
    $this->connectYoco($this->glow);
    ($this->in)(function () {
        expect(app(Deposits::class)->amountFor($this->service))->toBe(10000);
        $this->service->update(['deposit_type' => 'percent', 'deposit_value' => 20]);
        expect(app(Deposits::class)->amountFor($this->service))->toBe(9000);
    });
});

it('holds the time while paying into the business’s own account, then confirms once — verified with Yoco', function () {
    $this->yoco['paid'] = false;
    [$booking, $url] = ($this->hold)();
    expect($url)->toBe('https://c.yoco.com/checkout/ch_1')->and($booking->status)->toBe('pending_payment')
        ->and($booking->payment_reference)->toBe('dep_'.$this->glow->id.'_'.$booking->id)->and(($this->free)())->toBeFalse();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/checkouts') && $r['amount'] === 10000
        && $r['successUrl'] === 'https://glow.example/book/return?reference='.rawurlencode($booking->payment_reference) && $r['cancelUrl'] === 'https://glow.example/');

    app(Deposits::class)->confirm($booking->payment_reference); // not paid yet
    ($this->in)(fn () => expect($booking->refresh()->status)->toBe('pending_payment'));
    $this->yoco['paid'] = true;
    event(new BusinessPaymentReceived($this->glow->id, $booking->payment_reference)); // the webhook
    app(Deposits::class)->confirm($booking->payment_reference);                        // and the return
    ($this->in)(fn () => expect($booking->refresh()->status)->toBe('confirmed')->and($booking->paid_at)->not->toBeNull());
    expect(BookingHold::query()->count())->toBe(0);
});

it('does not accept a short payment', function () {
    $this->yoco['amount'] = 100;
    [$booking] = ($this->hold)();
    app(Deposits::class)->confirm($booking->payment_reference);
    ($this->in)(fn () => expect($booking->refresh()->status)->toBe('pending_payment'));
});

it('releases an unpaid hold after 15 minutes, and refunds a payment that arrives too late', function () {
    $this->yoco['paid'] = false;
    [$booking] = ($this->hold)();
    $this->travel(14)->minutes();
    expect(app(Deposits::class)->expireHolds())->toBe(0);
    $this->travel(2)->minutes();
    expect(app(Deposits::class)->expireHolds())->toBe(1)->and(($this->free)())->toBeTrue();

    $this->yoco['paid'] = true; // paid after the hold expired
    app(Deposits::class)->confirm($booking->payment_reference);
    ($this->in)(fn () => expect($booking->refresh()->status)->toBe('expired')->and($booking->refunded_at)->not->toBeNull());
    expect($this->yocoRefunds())->toBe(1);
});
