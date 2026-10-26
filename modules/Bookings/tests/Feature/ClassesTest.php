<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Mail\BookingMessageMail;
use Wurk\Bookings\Models\ClassSession;
use Wurk\Bookings\Models\MeetingConnection;
use Wurk\Bookings\Support\ClassBooker;
use Wurk\Bookings\Support\Reminders;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    Mail::fake();
    $this->createShards(1);
    $this->studio = $this->createTenant('flow-studio', name: 'Flow Studio');
    $this->studio->update(['timezone' => 'Africa/Johannesburg']);
    $p = app(PackageCatalog::class)->create([
        'key' => 'starter', 'name' => 'Starter', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
        'monthly_price_cents' => 29900, 'yearly_price_cents' => 299000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
        'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
        'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website', 'bookings'],
    ], null);
    app(Catalog::class)->subscribe($this->studio->id, $p->id, 'monthly', allowTrial: true);
    app(Entitlements::class)->flush();
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->studio, $fn);
    $this->class = ($this->in)(function () {
        MeetingConnection::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'provider' => 'fixed', 'fixed_url' => 'https://meet.example.com/flow']);

        return ClassSession::query()->create(['title' => 'Yoga Flow', 'starts_at' => '2027-05-03 18:00:00', 'ends_at' => '2027-05-03 19:00:00', 'capacity' => 2, 'location_type' => 'online']);
    });
    $this->site = 'http://flow-studio.'.config('wurk.domains.root').'/book/classes';
    $this->join = fn (string $name, string $email) => $this->postJson("{$this->site}/{$this->class->id}", ['name' => $name, 'phone' => '082 555 0101', 'email' => $email, 'consent' => '1']);
    $this->texts = fn (string $to) => Mail::sent(BookingMessageMail::class, fn ($m) => $m->hasTo($to))->map(fn ($m) => [$m->subjectLine, $m->text])->values()->all();
});

it('fills the class without overbooking, and everyone online gets the same session link', function () {
    expect($this->getJson($this->site)->json('classes.0'))->toMatchArray(['title' => 'Yoga Flow', 'online' => true, 'when' => 'Mon 3 May, 18:00', 'places_left' => 2]);
    $manage = ($this->join)('Sipho', 'sipho@example.com')->assertCreated()->assertJson(['service' => 'Class: Yoga Flow'])->json('manage_url');
    ($this->join)('Lerato', 'lerato@example.com')->assertCreated();
    ($this->join)('Thandi', 'thandi@example.com')->assertStatus(409)->assertJson(['message' => 'Sorry — this class is full. Please choose another one.']);
    expect($this->getJson($this->site)->json('classes.0.places_left'))->toBe(0);

    foreach (['sipho@example.com', 'lerato@example.com'] as $who) {
        expect(($this->texts)($who)[0][1])->toStartWith('Hi')->toContain('your Class: Yoga Flow booking at Flow Studio is confirmed for Mon 3 May, 18:00.')
            ->toContain('Join online: https://meet.example.com/flow');
    }
    $this->get($manage)->assertSee('Your joining link appears here 15 minutes before it starts.')->assertDontSee('https://meet.example.com/flow');
    $this->travelTo(CarbonImmutable::parse('2027-05-03 17:50', 'Africa/Johannesburg'));
    $this->get($manage)->assertSee('Join online now');
});

it('frees a place when someone leaves, and tells everyone when the business cancels the class', function () {
    $manage = ($this->join)('Sipho', 'sipho@example.com')->json('manage_url');
    ($this->join)('Lerato', 'lerato@example.com');
    $page = (string) $this->get($manage)->getContent();
    preg_match('#action="([^"]+/cancel\?[^"]+)"#', $page, $m);
    $this->post(html_entity_decode($m[1]))->assertOk()->assertSee('Your place is cancelled.');
    ($this->join)('Thandi', 'thandi@example.com')->assertCreated(); // the freed place

    ($this->in)(fn () => expect(app(ClassBooker::class)->cancelSession($this->class->refresh(), null))->toBe(2));
    foreach (['lerato@example.com', 'thandi@example.com'] as $who) {
        expect(array_column(($this->texts)($who), 0))->toContain('Booking cancelled — Flow Studio');
    }
});

it('reminds attendees 24 hours before', function () {
    ($this->join)('Sipho', 'sipho@example.com');
    $this->travelTo(CarbonImmutable::parse('2027-05-02 18:01', 'Africa/Johannesburg'));
    expect(app(Reminders::class)->sendDue())->toBe(1);
    expect(array_column(($this->texts)('sipho@example.com'), 0))->toBe(['Booking confirmed — Flow Studio', 'Reminder: your booking at Flow Studio']);
});
