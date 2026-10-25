<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\ReservationHours;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    $this->createShards(1);
    $this->bistro = $this->createTenant('the-bistro', name: 'The Bistro');
    $this->bistro->update(['timezone' => 'Africa/Johannesburg']);
    $p = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->bistro->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->enable = fn () => $this->ctx->run($this->bistro, function () {
        ReservationSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'enabled' => true, 'covers' => 6, 'sitting_minutes' => 90, 'max_party' => 6]);
        ReservationHours::query()->create(['weekday' => 0, 'starts' => '18:00', 'ends' => '21:00']);
    });
    $this->site = 'http://the-bistro.'.config('wurk.domains.root').'/book/tables';
    $this->guest = ['starts_at' => '2027-05-03T17:00:00+00:00', 'party' => 4, 'name' => 'Sipho', 'phone' => '082 555 0101', 'email' => 'sipho@example.com', 'consent' => '1'];
});

it('isn’t there until the owner switches tables on', function () {
    $this->getJson("{$this->site}/times?date=2027-05-03&party=2")->assertNotFound();
});

it('reserves a table, confirms it by email, refuses when full, and frees seats when the guest cancels', function () {
    ($this->enable)();
    expect($this->getJson("{$this->site}/times?date=2027-05-03&party=4")->assertOk()->json('times.0'))->toBe(['starts_at' => '2027-05-03T16:00:00+00:00', 'label' => '18:00']);

    $manage = $this->postJson($this->site, $this->guest)->assertCreated()->assertJson(['service' => 'Table for 4', 'when' => 'Monday 3 May, 19:00'])->json('manage_url');
    Mail::assertSent(BookingMessageMail::class, fn ($m) => str_starts_with($m->text, 'Hi Sipho, your Table for 4 booking at The Bistro is confirmed for Mon 3 May, 19:00.'));
    $this->postJson($this->site, [...$this->guest, 'name' => 'Lerato', 'party' => 3])->assertStatus(409)->assertJson(['message' => 'Sorry — we’re full at that time. Please choose another time.']);

    $page = (string) $this->get($manage)->assertOk()->assertSee('Table for 4')->getContent();
    preg_match('#action="([^"]+/cancel\?[^"]+)"#', $page, $m);
    $this->post(html_entity_decode($m[1]))->assertOk()->assertSee('Your reservation is cancelled.');
    Mail::assertSent(BookingMessageMail::class, fn ($m) => $m->subjectLine === 'Booking cancelled — The Bistro');
    $this->postJson($this->site, [...$this->guest, 'name' => 'Lerato', 'party' => 3])->assertCreated(); // seats free again
});
