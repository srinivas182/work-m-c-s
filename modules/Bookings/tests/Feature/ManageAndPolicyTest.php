<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Support\Booker;
use Wurk\Bookings\Support\CancellationPolicy;
use Wurk\Bookings\Support\Deposits;
use Wurk\Bookings\Support\ManageLink;
use Wurk\Messaging\Support\ProviderSettings;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

const STAFF_ONE = '01J00000000000000000000001';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    app(ProviderSettings::class)->save('payments.paystack', 'paystack', ['public_key' => 'pk', 'secret_key' => 'sk_test'], [], true, null);
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://x']]),
        'api.paystack.co/transaction/verify/*' => Http::response(['status' => true, 'data' => ['status' => 'success', 'amount' => 10000]]),
        'api.paystack.co/refund' => Http::response(['status' => true]),
    ]);
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->glow, $fn);
    [$this->booking, $this->link] = ($this->in)(function () {
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000, 'deposit_type' => 'fixed', 'deposit_value' => 10000]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => STAFF_ONE, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => STAFF_ONE, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);
        BookingSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'payout_subaccount' => 'ACCT_glow']);
        $b = app(Booker::class)->book($service, CarbonImmutable::parse('2027-05-03 09:00', 'Africa/Johannesburg'), ['name' => 'Sipho', 'email' => 's@example.com'], STAFF_ONE, 'website', status: 'pending_payment');
        app(Deposits::class)->start($b, 10000, 's@example.com', 'https://x');
        app(Deposits::class)->confirm((string) $b->refresh()->payment_reference); // paid

        return [$b->refresh(), ManageLink::for($b->refresh(), 'glow-salon')];
    });
    $this->action = fn (string $html, string $what) => html_entity_decode((string) (preg_match('#action="([^"]*/'.$what.'\?[^"]*)"#', $html, $m) ? $m[1] : ''));
    $this->refunds = fn () => count(Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/refund')));
});

it('refuses tampered links, and shows the booking, the policy and what cancelling now means', function () {
    $this->get($this->link.'x')->assertForbidden();
    $this->get($this->link)->assertOk()->assertSee('Knotless braids')->assertSee('Monday 3 May, 09:00')
        ->assertSee(CancellationPolicy::defaultWording(24))->assertSee('If you cancel now, your deposit will be refunded.');
});

it('moves the booking to another free time from the same signed page', function () {
    $page = (string) $this->get($this->link.'&date=2027-05-03')->assertOk()->assertSee('11:00')->getContent();
    $this->post(($this->action)($page, 'move'), ['starts_at' => '2027-05-03T09:00:00+00:00'])->assertOk()->assertSee('Your booking has been moved.');
    ($this->in)(fn () => expect($this->booking->refresh()->starts_at->setTimezone('Africa/Johannesburg')->format('H:i'))->toBe('11:00'));
});

it('refunds the deposit when the customer cancels in time, and keeps it when it’s too late', function () {
    $page = (string) $this->get($this->link)->getContent();
    $this->post(($this->action)($page, 'cancel'))->assertOk()->assertSee('Your booking is cancelled and your deposit is being refunded.');
    expect(($this->refunds)())->toBe(1);

    // Another paid booking, cancelled inside the 24-hour window.
    $late = ($this->in)(function () {
        $service = BookingService::query()->sole();
        $b = app(Booker::class)->book($service, CarbonImmutable::parse('2027-05-03 13:00', 'Africa/Johannesburg'), ['name' => 'Lerato', 'email' => 'l@example.com'], STAFF_ONE, 'website', status: 'pending_payment');
        app(Deposits::class)->start($b, 10000, 'l@example.com', 'https://x');
        app(Deposits::class)->confirm((string) $b->refresh()->payment_reference);

        return $b->refresh();
    });
    $this->travelTo(CarbonImmutable::parse('2027-05-03 08:00', 'Africa/Johannesburg'));
    $link = ($this->in)(fn () => ManageLink::for($late, 'glow-salon'));
    $page = (string) $this->get($link)->assertSee('If you cancel now, the deposit is kept.')->getContent();
    $this->post(($this->action)($page, 'cancel'))->assertSee('Your booking is cancelled.')->assertDontSee('being refunded');
    expect(($this->refunds)())->toBe(1); // no second refund
});

it('always refunds when the business cancels', function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-03 08:30', 'Africa/Johannesburg')); // inside the window
    ($this->in)(fn () => expect(app(CancellationPolicy::class)->businessCancels($this->booking, null))->toBe(['refunded' => true]));
});
