<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\InteractsWithShards;
use Wurk\Bookings\Models\ReservationHours;
use Wurk\Bookings\Models\ReservationSettings;
use Wurk\Bookings\Support\Reserver;
use Wurk\Bookings\Support\TableAvailability;
use Wurk\Bookings\Support\TableFull;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithShards::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-05-01 10:00', 'Africa/Johannesburg'));
    $this->createShards(1);
    $this->bistro = $this->createTenant('the-bistro');
    $this->bistro->update(['timezone' => 'Africa/Johannesburg']);
    $this->ctx = app(TenantContext::class);
    $this->in = fn (callable $fn) => $this->ctx->run($this->bistro, $fn);
    ($this->in)(function () {
        ReservationSettings::query()->create(['tenant_id' => app(TenantContext::class)->id(), 'enabled' => true, 'covers' => 10, 'sitting_minutes' => 90, 'step_minutes' => 30, 'max_party' => 8]);
        ReservationHours::query()->create(['weekday' => 0, 'starts' => '18:00', 'ends' => '21:00']); // Mondays: arrivals 18:00–21:00
    });
    $this->times = fn (int $party) => ($this->in)(fn () => array_column(app(TableAvailability::class)->on('2027-05-03', $party), 'label'));
    $this->reserve = fn (string $hm, int $party, bool $owner = false) => ($this->in)(fn () => app(Reserver::class)->reserve(
        CarbonImmutable::parse("2027-05-03 {$hm}", 'Africa/Johannesburg'), $party, ['name' => 'Guest'], $owner ? 'owner' : 'website', $owner));
});

it('offers arrivals every 30 minutes up to the last arrival — none when off or the party is too large', function () {
    expect(($this->times)(2))->toBe(['18:00', '18:30', '19:00', '19:30', '20:00', '20:30', '21:00'])
        ->and(($this->times)(9))->toBe([]);
    ($this->in)(fn () => ReservationSettings::query()->update(['enabled' => false]));
    expect(($this->times)(2))->toBe([]);
});

it('fits parties to the covers while their sittings overlap', function () {
    ($this->reserve)('19:00', 6);
    expect(($this->times)(6))->not->toContain('19:30')->toContain('20:30')     // the 19:00 party has left by 20:30
        ->and(($this->times)(4))->toContain('19:30');
    expect(fn () => ($this->reserve)('19:30', 6))->toThrow(TableFull::class, 'we’re full at that time');
});

it('counts guests present at the same moment — not everyone whose sitting overlaps', function () {
    ($this->reserve)('18:00', 6); // until 19:30
    ($this->reserve)('19:30', 4); // from 19:30 — never at the table with the first party
    expect(($this->times)(2))->toContain('19:00'); // peak 6 + 2 fits 10 (a naive sum would refuse)
});

it('lets the owner seat at any time if there’s room — never past the covers — and cancelling frees seats', function () {
    $late = ($this->reserve)('22:15', 8, owner: true); // after the last online arrival
    expect(fn () => ($this->reserve)('22:30', 4, owner: true))->toThrow(TableFull::class);
    ($this->in)(fn () => app(Reserver::class)->setStatus($late, 'cancelled', null));
    expect(($this->reserve)('22:30', 4, owner: true)->party_size)->toBe(4);
});
