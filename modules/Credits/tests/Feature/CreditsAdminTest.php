<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Credits\Contracts\CreditWallet;
use Wurk\Credits\Models\CreditRate;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
    $this->context = app(TenantContext::class);
});

it('lets credit managers set dated rates; support can only look', function () {
    $this->actingAs($this->staffWithRole('support'))->post($this->admin('/credits/rates'), ['country' => 'ZA', 'channel' => 'sms', 'credits' => 4])->assertForbidden();

    $admin = $this->staffWithRole('super-admin', 'admin@wurk.co.za');
    $this->actingAs($admin)->post($this->admin('/credits/rates'), ['country' => 'ZA', 'channel' => 'sms', 'credits' => 3.5])->assertSessionHas('status');
    expect(CreditRate::query()->where('channel', 'sms')->orderByDesc('effective_from')->orderByDesc('id')->value('credits_milli'))->toBe(3500);

    $this->actingAs($admin)->get($this->admin('/credits/rates'))
        ->assertInertia(fn (Assert $p) => $p->component('Credits::Admin/Rates')->where('countries.0.rates.sms', fn ($v) => (float) $v === 3.5));
});

it('lets staff adjust a business balance with a reason, never below zero', function () {
    $admin = $this->staffWithRole('super-admin');
    $url = $this->admin("/businesses/{$this->glow->id}/credits");

    $this->actingAs($admin)->post($url, ['credits' => 50, 'reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($admin)->post($url, ['credits' => 50, 'reason' => 'Goodwill after outage'])->assertSessionHas('status');
    $this->actingAs($admin)->post($url, ['credits' => -80, 'reason' => 'Correction too big'])->assertSessionHasErrors('credits');

    $this->actingAs($this->staffWithRole('support', 'support@wurk.co.za'))->get($url)
        ->assertInertia(fn (Assert $p) => $p->component('Credits::Admin/Business')->where('balance', fn ($b) => (float) $b === 50.0)->where('canAdjust', false));
});

it('shows owners their credits and lets them set the alert level; staff without permission get 404', function () {
    $owner = $this->person();
    $desk = $this->person(['email' => 'desk@glow.co.za', 'phone' => null]);
    foreach ([[$owner, 'owner'], [$desk, 'staff']] as [$who, $role]) {
        $this->member($who, $this->glow);
        $this->context->run($this->glow, fn () => app(Team::class)->add($who->id, 'X', $role));
    }
    $this->context->run($this->glow, fn () => app(CreditWallet::class)->grant('purchase', 42000, 'buy:1', 'Purchase'));

    $this->actingAs($owner)->get($this->app('/glow-salon/settings/credits'))
        ->assertInertia(fn (Assert $p) => $p->component('Credits::Business')->where('balance', fn ($b) => (float) $b === 42.0)->where('rates.sms.credits', fn ($v) => (float) $v === 3.0));
    $this->actingAs($owner)->put($this->app('/glow-salon/settings/credits/alert'), ['low_balance' => 50])->assertSessionHas('status');
    $this->actingAs($desk)->get($this->app('/glow-salon/settings/credits'))->assertNotFound();
});
