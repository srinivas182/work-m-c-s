<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithShards;
use Wurk\Audit\Models\AuditEvent;
use Wurk\Bookings\Models\BookingHold;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Support\Availability;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\Deposits;
use Wurk\Crm\Contracts\PurchaseMade;
use Wurk\Payments\Contracts\BusinessPaymentReceived;
use Wurk\Payments\Contracts\StoreCredit;
use Wurk\Payments\Support\ReturnWindow;
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

it('counts the return window from the appointment: the deposit is final 14 days after it, and refunds close then', function () {
    Event::fake([PurchaseMade::class]);
    [$booking] = ($this->hold)();
    event(new BusinessPaymentReceived($this->glow->id, $booking->payment_reference));
    app(Deposits::class)->confirm($booking->payment_reference);
    Event::assertDispatched(PurchaseMade::class, fn ($e) => $e->sourceType === 'booking' && $e->paidCents === 10000
        && CarbonImmutable::instance($e->finalAt)->equalTo(CarbonImmutable::parse('2027-05-17 09:00', 'Africa/Johannesburg'))); // the appointment + 14 days

    ($this->in)(function () use ($booking) {
        $this->travelTo(CarbonImmutable::parse('2027-05-17 09:01', 'Africa/Johannesburg'));
        expect(app(CancellationPolicy::class)->refund($booking->refresh()))->toBeFalse()
            ->and($booking->refresh()->refunded_at)->toBeNull()
            ->and(AuditEvent::query()->where('action', 'bookings.refund_window_closed')->count())->toBe(1);
        $this->travelTo(CarbonImmutable::parse('2027-05-17 08:59', 'Africa/Johannesburg'));
        expect(app(CancellationPolicy::class)->refund($booking->refresh()))->toBeTrue(); // still open
    });
});

it('gives no deposit refunds when the business gives no refunds (0 days)', function () {
    [$booking] = ($this->hold)();
    event(new BusinessPaymentReceived($this->glow->id, $booking->payment_reference));
    app(Deposits::class)->confirm($booking->payment_reference);
    ($this->in)(function () use ($booking) {
        app(ReturnWindow::class)->set(0);
        expect(app(CancellationPolicy::class)->refund($booking->refresh()))->toBeFalse();
    });
});

describe('paying a deposit partly or wholly from the wallet', function () {
    beforeEach(function () {
        // Bookings uses the wallet only through Payments' StoreCredit contract: a recording fake stands in for it.
        $this->credit = new class implements StoreCredit
        {
            public array $spent = [];

            public array $back = [];

            public function available(string $contactId): int
            {
                return 20000;
            }

            public int $share = 100; // the business's share rule, in percent

            public function usableFor(string $contactId, int $orderTotalCents): int
            {
                return min(20000, intdiv($orderTotalCents * $this->share, 100));
            }

            public array $branches = [];

            public function spend(string $contactId, int $cents, string $sourceType, string $sourceId, int $orderTotalCents, ?string $branchId = null): void
            {
                $this->branches[] = $branchId;
                if ($cents > 20000) {
                    throw new DomainException('Only R200.00 of the wallet can be used on this order.');
                }
                $this->spent[] = [$sourceType, $cents];
            }

            public function giveBack(string $sourceType, string $sourceId, int $cents): void
            {
                $this->back[] = [$sourceType, $cents];
            }

            public function passFor(string $contactId): string
            {
                return 'good';
            }

            public function contactFromPass(string $pass): ?string
            {
                return $pass === 'good' ? 'contact-1' : null;
            }
        };
        app()->instance(StoreCredit::class, $this->credit);
        $this->connectYoco($this->glow);
        $this->book = fn (?array $wallet, string $at = '09:00') => ($this->in)(function () use ($wallet, $at) {
            $booking = app(Booker::class)->book($this->service, CarbonImmutable::parse('2027-05-03 '.$at, 'Africa/Johannesburg'), ['name' => 'Sipho', 'email' => 'sipho@example.com'], STYLIST, 'website', status: 'pending_payment');
            $url = app(Deposits::class)->start($booking, 10000, 'sipho@example.com', 'https://glow.example/book/return', $wallet);

            return [$booking->refresh(), $url];
        });
    });

    it('charges the card only what’s left, rewards the full deposit, and gives the wallet part back on a refund', function () {
        Event::fake([PurchaseMade::class]);
        [$booking, $url] = ($this->book)(['pass' => 'good', 'cents' => 3000]);
        expect($booking->wallet_cents)->toBe(3000)->and($url)->toContain('yoco')->and($this->credit->spent)->toBe([['booking', 3000]]);
        expect($this->credit->branches)->toBe([$booking->branch_id]); // where it was spent (13.9b)
        expect(end($this->yocoAmounts))->toBe(7000);                                       // the card was asked only for what's left
        $this->yoco['paid'] = true;
        expect(app(Deposits::class)->confirm($booking->payment_reference)?->status)->toBe('confirmed');
        Event::assertDispatched(PurchaseMade::class, fn ($e) => $e->paidCents === 10000); // decision E
        ($this->in)(fn () => expect(app(CancellationPolicy::class)->refund($booking->refresh()))->toBeTrue());
        expect($this->credit->back)->toBe([['booking', 3000]]);
    });

    it('uses only what the share rule allows for the actual deposit — the card pays the rest', function () {
        $this->credit->share = 50;
        [$booking] = ($this->book)(['pass' => 'good', 'cents' => 10000]); // the form offered "up to", based on the price
        expect($booking->wallet_cents)->toBe(5000)->and(end($this->yocoAmounts))->toBe(5000)->and($booking->status)->toBe('pending_payment');
    });

    it('confirms at once when the wallet pays it all, refuses forged passes, and gives the wallet back when a hold expires', function () {
        [$paid, $url] = ($this->book)(['pass' => 'good', 'cents' => 10000]);
        expect($paid->status)->toBe('confirmed')->and($url)->toStartWith('https://glow.example/book/return?reference=');
        expect(fn () => ($this->book)(['pass' => 'forged', 'cents' => 1000], '11:00'))->toThrow(DomainException::class, 'Your wallet couldn’t be used');
        $this->travelTo(CarbonImmutable::parse('2027-05-01 11:00', 'Africa/Johannesburg'));
        [$held] = ($this->book)(['pass' => 'good', 'cents' => 2000], '13:00');
        $this->travel(16)->minutes();
        app(Deposits::class)->expireHolds();
        expect(($this->in)(fn () => $held->refresh()->status))->toBe('expired')->and($this->credit->back)->toContain(['booking', 2000]);
    });
});
