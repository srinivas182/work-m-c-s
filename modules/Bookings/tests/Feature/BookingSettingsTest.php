<?php

declare(strict_types=1);

use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Bookings\Models\BookingHours;
use Wurk\Bookings\Models\BookingService;
use Wurk\Bookings\Models\TimeOff;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
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
    $this->owner = $this->person();
    $this->member($this->owner, $this->glow);
    $this->ctx = app(TenantContext::class);
    $this->ownerMember = $this->ctx->run($this->glow, fn () => app(Team::class)->add($this->owner->id, 'Zanele', 'owner'));
    $this->base = $this->app('/glow-salon/bookings');
    $this->service = ['name' => 'Knotless braids', 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 15,
        'price_cents' => 45000, 'deposit_type' => 'none', 'deposit_value' => 0, 'active' => true, 'members' => [$this->ownerMember->id]];
});

it('is only for packages that include bookings', function () {
    ($this->subscribe)(['website']);
    expect($this->actingAs($this->owner)->post("{$this->base}/services", $this->service)->status())->toBeIn([403, 404]);
});

it('saves a service in 5-minute steps, done by real team members', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'duration_minutes' => 47])->assertSessionHasErrors(['duration_minutes' => 'Use steps of 5 minutes.']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'members' => []])->assertSessionHasErrors(['members' => 'Choose at least one team member who does this service.']);
    $this->actingAs($this->owner)->post("{$this->base}/services", [...$this->service, 'members' => ['01J0000000000000000000NOPE']])->assertSessionHasErrors('members.0');

    $this->actingAs($this->owner)->post("{$this->base}/services", $this->service)->assertSessionHas('status', 'Service saved.');
    $this->ctx->run($this->glow, fn () => expect(BookingService::query()->sole()->only(['name', 'duration_minutes', 'buffer_after_minutes']))
        ->toBe(['name' => 'Knotless braids', 'duration_minutes' => 60, 'buffer_after_minutes' => 15]));
});

it('saves weekly hours, refusing overlaps and backwards times', function () {
    ($this->subscribe)(['bookings']);
    $url = "{$this->base}/hours/{$this->ownerMember->id}";
    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 0, 'starts' => '09:00', 'ends' => '13:00'], ['weekday' => 0, 'starts' => '12:00', 'ends' => '17:00']]])
        ->assertSessionHasErrors(['hours.0.starts' => 'These hours overlap other hours on the same day.']);
    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 1, 'starts' => '17:00', 'ends' => '09:00']]])->assertSessionHasErrors(['hours.0.ends' => 'The end must be after the start.']);

    $this->actingAs($this->owner)->put($url, ['hours' => [['weekday' => 0, 'starts' => '09:00', 'ends' => '12:00'], ['weekday' => 0, 'starts' => '13:00', 'ends' => '17:00']]])->assertSessionHas('status', 'Hours saved.');
    $this->ctx->run($this->glow, fn () => expect(BookingHours::query()->count())->toBe(2));
});

it('takes time off in local time and stores it in UTC', function () {
    ($this->subscribe)(['bookings']);
    $this->actingAs($this->owner)->post("{$this->base}/time-off", ['member_id' => null, 'starts_at' => '2027-04-27 00:00', 'ends_at' => '2027-04-28 00:00', 'reason' => 'Freedom Day'])->assertSessionHas('status');
    $this->ctx->run($this->glow, fn () => expect(TimeOff::query()->sole()->starts_at->utc()->toIso8601String())->toBe('2027-04-26T22:00:00+00:00'));
});
