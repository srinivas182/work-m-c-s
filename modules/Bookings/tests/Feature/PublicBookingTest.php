<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakesYoco;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Access\Models\Member;
use Wurk\Bookings\Models\Booking;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\BookingSettings;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Models\MemberMeetingLink;
use Wurk\Bookings\Models\MemberRegistration;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class, FakesYoco::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon');
    $this->glow->update(['timezone' => 'Africa/Johannesburg']);
    $this->subscribe = function (array $modules) {
        $p = app(PackageCatalog::class)->create([
            'key' => 'p'.count($modules), 'name' => 'Package', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => $modules,
        ], null);
        app(Catalog::class)->subscribe($this->glow->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
    };
    $this->ctx = app(TenantContext::class);
    [$this->service, $this->zanele] = $this->ctx->run($this->glow, function () {
        $zanele = app(Team::class)->add($this->person()->id, 'Zanele', 'owner');
        $service = BookingService::query()->create(['name' => 'Knotless braids', 'duration_minutes' => 60, 'price_cents' => 45000]);
        BookingService::query()->create(['name' => 'Retired service', 'duration_minutes' => 30, 'active' => false]);
        DB::connection(app(TenantContext::class)->connectionName())->table('booking_service_member')->insert(['service_id' => $service->id, 'member_id' => $zanele->id, 'tenant_id' => app(TenantContext::class)->id()]);
        BookingHours::query()->create(['member_id' => $zanele->id, 'weekday' => 0, 'starts' => '09:00', 'ends' => '17:00']);

        return [$service, $zanele];
    });
    $this->site = 'http://glow-salon.'.config('wurk.domains.root').'/book';
    $this->booking = ['service' => $this->service->id, 'starts_at' => '2027-05-03T07:00:00+00:00', 'name' => 'Sipho', 'phone' => '082 555 0101', 'consent' => '1'];
});

it('lists what can be booked and the free times, in the business’s local time', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->getJson("{$this->site}/services")->assertOk()->assertJsonPath('timezone', 'Africa/Johannesburg')
        ->assertJsonCount(1, 'services')->assertJsonPath('services.0.name', 'Knotless braids')->assertJsonPath('services.0.members.0.name', 'Zanele');
    $times = $this->getJson("{$this->site}/times?service={$this->service->id}&date=2027-05-03")->assertOk()->json('times');
    expect($times[0])->toBe(['starts_at' => '2027-05-03T07:00:00+00:00', 'label' => '09:00']);
});

it('books a free time once — the same time again is refused kindly', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->postJson($this->site, $this->booking)->assertCreated()->assertJson(['booked' => true, 'service' => 'Knotless braids', 'when' => 'Monday 3 May, 09:00']);
    $this->postJson($this->site, [...$this->booking, 'name' => 'Lerato'])->assertStatus(409)->assertJson(['message' => 'Sorry — that time has just been taken. Please choose another.']);
    $this->ctx->run($this->glow, fn () => expect(Booking::query()->sole()->only(['customer_name', 'source']))->toBe(['customer_name' => 'Sipho', 'source' => 'website']));
});

it('asks for consent, and quietly books nothing for bots', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->postJson($this->site, [...$this->booking, 'consent' => null])->assertJsonValidationErrors(['consent' => 'Please agree to be contacted about your booking.']);
    $this->postJson($this->site, [...$this->booking, 'website' => 'http://spam.example'])->assertCreated();
    $this->ctx->run($this->glow, fn () => expect(Booking::query()->count())->toBe(0));
});

it('is not there for packages without bookings', function () {
    ($this->subscribe)(['website']);
    $this->getJson("{$this->site}/services")->assertNotFound();
    $this->postJson($this->site, $this->booking)->assertNotFound();
});

it('takes a deposit through Paystack when the service needs one, and confirms on return', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->connectYoco($this->glow);
    $this->ctx->run($this->glow, function () {
        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 10000]);
    });
    $this->fakeYoco();

    $this->postJson($this->site, $this->booking)->assertJsonValidationErrors(['email' => 'Please add your email — a deposit is needed for this service, and the receipt is emailed.']);
    $this->postJson($this->site, [...$this->booking, 'email' => 'sipho@example.com'])->assertCreated()->assertJson(['pay_url' => 'https://c.yoco.com/checkout/ch_1', 'deposit_cents' => 10000]);
    $reference = $this->ctx->run($this->glow, fn () => Booking::query()->sole()->payment_reference);

    $this->get("{$this->site}/return?reference={$reference}")->assertOk()->assertSee('You’re booked')->assertSee('Monday 3 May, 09:00');
    $this->ctx->run($this->glow, fn () => expect(Booking::query()->sole()->status)->toBe('confirmed'));
});

it('doesn’t hold the time when payments are unavailable', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->fakeYoco();
    $this->connectYoco($this->glow);
    $this->yoco['up'] = false; // connected, but Yoco can't be reached
    $this->ctx->run($this->glow, function () {
        $this->service->update(['deposit_type' => 'fixed', 'deposit_value' => 10000]);
    }); // no Paystack keys configured
    $this->postJson($this->site, [...$this->booking, 'email' => 'sipho@example.com'])->assertStatus(503)->assertJson(['message' => 'Online payment isn’t available right now. Please call or message us to book.']);
    $this->getJson("{$this->site}/times?service={$this->service->id}&date=2027-05-03")->assertJsonPath('times.0.label', '09:00'); // still free
});

it('lets the client choose online, and shows the joining link only from 15 minutes before', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->ctx->run($this->glow, function () {
        $this->service->update(['location_type' => 'either']);
        MeetingConnection::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'provider' => 'fixed', 'fixed_url' => 'https://meet.example.com/glow']);
    });
    expect($this->getJson("{$this->site}/services")->json('services.0.location'))->toBe('either');
    $manage = $this->postJson($this->site, [...$this->booking, 'online' => true])->assertCreated()->json('manage_url');
    $this->ctx->run($this->glow, fn () => expect(Booking::query()->sole()->only(['online', 'meeting_url']))->toBe(['online' => true, 'meeting_url' => 'https://meet.example.com/glow']));

    $this->get($manage)->assertSee('Your joining link appears here 15 minutes before it starts.')->assertDontSee('https://meet.example.com/glow');
    $this->travelTo(CarbonImmutable::parse('2027-05-03 08:50', 'Africa/Johannesburg'));
    $this->get($manage)->assertSee('Join online now')->assertSee('https://meet.example.com/glow');
});

it('charges the full price upfront only for online appointments — and issues the link only once paid', function () {
    ($this->subscribe)(['website', 'bookings']);
    $this->connectYoco($this->glow);
    $this->ctx->run($this->glow, function () {
        $this->service->update(['location_type' => 'either', 'deposit_type' => 'full', 'deposit_when' => 'online']);
        MeetingConnection::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'provider' => 'fixed', 'fixed_url' => 'https://meet.example.com/glow']);
    });
    $this->fakeYoco();

    // In person: pay at the desk — confirmed straight away.
    $this->postJson($this->site, [...$this->booking, 'email' => 'sipho@example.com'])->assertCreated()->assertJson(['booked' => true])->assertJsonMissing(['pay_url' => 'https://c.yoco.com/checkout/ch_1']);

    // Online: the full price first, and no link until it's paid.
    $this->postJson($this->site, [...$this->booking, 'starts_at' => '2027-05-03T09:00:00+00:00', 'email' => 'lerato@example.com', 'name' => 'Lerato', 'online' => true])
        ->assertCreated()->assertJson(['pay_url' => 'https://c.yoco.com/checkout/ch_1', 'deposit_cents' => 45000]);
    $online = fn () => $this->ctx->run($this->glow, fn () => Booking::query()->where('customer_name', 'Lerato')->sole());
    expect($online()->status)->toBe('pending_payment')->and($online()->meeting_url)->toBeNull();

    $this->get("{$this->site}/return?reference={$online()->payment_reference}")->assertOk()->assertSee('You’re booked');
    expect($online()->status)->toBe('confirmed')->and($online()->meeting_url)->toBe('https://meet.example.com/glow');
});

it('at a health practice, books online consultations only with the patient’s consent — and shows the practitioner’s registration', function () {
    ($this->subscribe)(['bookings']);
    $zanele = $this->ctx->run($this->glow, function () {
        $this->service->update(['location_type' => 'either']);
        $id = Member::query()->sole()->id;
        MemberMeetingLink::query()->create(['member_id' => $id, 'url' => 'https://meet.google.com/abc']);
        MemberRegistration::query()->create(['member_id' => $id, 'council' => 'HPCSA', 'number' => 'MP0123456']);
        BookingSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'health_practice' => true]);

        return $id;
    });
    $this->getJson("{$this->site}/services")->assertOk()
        ->assertJsonPath('health.version', 'v1')->assertJsonPath('services.0.members.0.registration', 'HPCSA MP0123456');

    $this->postJson("{$this->site}", [...$this->booking, 'online' => true])->assertStatus(422)
        ->assertJsonValidationErrors(['health_consent' => 'Please read and agree to the video-consultation terms to book online.']);
    $manage = $this->postJson("{$this->site}", [...$this->booking, 'online' => true, 'health_consent' => true])->assertCreated()->assertJsonPath('practitioner', 'HPCSA MP0123456')->json('manage_url');
    $this->get($manage)->assertOk()->assertSee('Your practitioner: <strong>Zanele — HPCSA MP0123456</strong>', false)->assertSee('You agreed to the video-consultation terms on 1 May, 10:00');
    $this->ctx->run($this->glow, fn () => expect(Booking::query()->sole()->only(['consent_version', 'member_id']))->toBe(['consent_version' => 'v1', 'member_id' => $zanele])
        ->and(Booking::query()->sole()->consent_at)->not->toBeNull());

    // In person needs no video consent.
    $this->postJson("{$this->site}", [...$this->booking, 'starts_at' => '2027-05-03T09:00:00+00:00'])->assertCreated()->assertJsonPath('practitioner', null);
});
