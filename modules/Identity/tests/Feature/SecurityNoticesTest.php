<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Identity\Events\MfaDisabled;
use Wurk\Identity\Events\MfaEnabled;
use Wurk\Identity\Listeners\SendSecurityNotice;
use Wurk\Identity\Mail\OtpCodeMail;
use Wurk\Identity\Mail\SecurityNoticeMail;
use Wurk\Plans\Actions\PackageCatalog;
use Wurk\Plans\Contracts\Catalog;
use Wurk\Plans\Contracts\Entitlements;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

const CHROME_ANDROID = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36';
const FIREFOX_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:132.0) Gecko/20100101 Firefox/132.0';

beforeEach(function () {
    Mail::fake();
    $this->person = $this->person(['email' => 'thandi@glow.co.za', 'phone' => '+27821234567', 'password' => 'sunny days in durban']);
    $this->signIn = fn (string $agent) => $this->withHeader('User-Agent', $agent)->withServerVariables(['REMOTE_ADDR' => '41.13.7.9'])
        ->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'sunny days in durban']);
});

it('emails the person when their account is signed in to on a new device — not on their first device, nor again on a known one', function () {
    ($this->signIn)(CHROME_ANDROID);
    Mail::assertNothingSent();                                          // the very first device
    auth()->logout();
    ($this->signIn)(CHROME_ANDROID);
    Mail::assertNothingSent();                                          // a device they've used
    auth()->logout();
    ($this->signIn)(FIREFOX_WINDOWS);
    Mail::assertSent(SecurityNoticeMail::class, fn (SecurityNoticeMail $m) => $m->hasTo('thandi@glow.co.za') && $m->kind === 'new_device'
        && $m->device === 'Firefox on Windows' && $m->ip === '41.13.x.x' && str_contains($m->render(), 'If it wasn\'t you:')
        && $m->envelope()->subject === 'New sign-in to your WURK account');
});

it('emails the person when their password changes or two-step sign-in is turned on or off — never people with only a phone', function () {
    $this->actingAs($this->person)->put($this->app('/account/password'), ['current_password' => 'sunny days in durban', 'password' => 'rainy days in cape town', 'password_confirmation' => 'rainy days in cape town'])
        ->assertSessionHasNoErrors();
    Mail::assertSent(SecurityNoticeMail::class, fn (SecurityNoticeMail $m) => $m->kind === 'password' && str_contains($m->render(), 'Your other devices were signed out.'));
    event(new MfaEnabled($this->person));
    event(new MfaDisabled($this->person));
    Mail::assertSent(SecurityNoticeMail::class, fn (SecurityNoticeMail $m) => $m->kind === 'mfa_on');
    Mail::assertSent(SecurityNoticeMail::class, fn (SecurityNoticeMail $m) => $m->kind === 'mfa_off');

    Mail::fake();
    $phoneOnly = $this->person(['email' => null, 'phone' => '+27829999999']);
    event(new MfaDisabled($phoneOnly));
    Mail::assertNothingSent();
    expect(SendSecurityNotice::partialIp('2c0f:f4c0:1234:5678::1'))->toBe('2c0f:f4c0:1234:…');
});

describe('confirm it’s you before downloading all of a business’s data', function () {
    beforeEach(function () {
        $this->createShards(1);
        $glow = $this->createTenant('glow-salon', name: 'Glow Salon');
        $p = app(PackageCatalog::class)->create([
            'key' => 'growth', 'name' => 'Growth', 'type' => 'self_managed', 'visibility' => 'public', 'display_order' => 1,
            'monthly_price_cents' => 59900, 'yearly_price_cents' => 599000, 'trial_days' => 14, 'storage_mb' => 1024, 'staff_limit' => 5,
            'multi_branch' => false, 'max_branches' => 1, 'custom_domain' => false, 'monthly_credits' => 0, 'referral_max_levels' => 7,
            'referral_max_commission_bp' => 3000, 'managed_fee_cents' => null, 'modules' => ['website'],
        ], null);
        app(Catalog::class)->subscribe($glow->id, $p->id, 'monthly', allowTrial: true);
        app(Entitlements::class)->flush();
        $this->member($this->person, $glow);
        app(TenantContext::class)->run($glow, fn () => app(Team::class)->add($this->person->id, 'Thandi', 'owner'));
        $this->data = $this->app('/glow-salon/settings/your-data');
    });

    it('asks first, takes the password, then lets the export start — for 15 minutes', function () {
        $this->actingAs($this->person)->from($this->data)->post($this->data.'/export')
            ->assertRedirect($this->app('/account/confirm?to='.rawurlencode('/glow-salon/settings/your-data')))
            ->assertSessionHas('warning', 'Please confirm it’s you, then try again.');
        $this->actingAs($this->person)->get($this->app('/account/confirm?to=/glow-salon/settings/your-data'))
            ->assertInertia(fn (Assert $p) => $p->component('Identity::Confirm')->where('hasPassword', true)->where('destination', fn ($d) => str_contains((string) $d, '@')));
        $this->actingAs($this->person)->post($this->app('/account/confirm/password'), ['password' => 'wrong one', 'to' => '/glow-salon/settings/your-data'])
            ->assertSessionHasErrors(['password' => 'That password isn’t right.']);
        $this->actingAs($this->person)->post($this->app('/account/confirm/password'), ['password' => 'sunny days in durban', 'to' => '/glow-salon/settings/your-data'])
            ->assertRedirect('/glow-salon/settings/your-data');
        $this->actingAs($this->person)->post($this->data.'/export')->assertSessionHas('status', fn ($s) => str_starts_with((string) $s, 'We’re building your export.'));

        $this->travel(16)->minutes();
        $this->actingAs($this->person)->from($this->data)->post($this->data.'/export')->assertRedirectContains('/account/confirm');
    });

    it('confirms with a code for people without a password, and never sends anyone elsewhere afterwards', function () {
        $this->person->forceFill(['password' => null])->save();
        $this->actingAs($this->person)->get($this->app('/account/confirm?to=/x'))->assertInertia(fn (Assert $p) => $p->where('hasPassword', false));
        $this->actingAs($this->person)->post($this->app('/account/confirm/code'))->assertSessionHas('status', 'We sent you a code.');
        $code = '';
        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $m) use (&$code) {
            $code = $m->code;

            return $m->purpose === 'confirm' && str_contains($m->render(), 'confirm it’s you');
        });
        $this->actingAs($this->person)->post($this->app('/account/confirm/code/verify'), ['code' => $code === '000000' ? '111111' : '000000', 'to' => '/'])
            ->assertSessionHasErrors(['code' => 'That code isn’t right, or it has expired.']);
        $this->actingAs($this->person)->post($this->app('/account/confirm/code/verify'), ['code' => $code, 'to' => 'https://evil.example/steal'])
            ->assertRedirect('/');                                          // only a path on this site
        $this->actingAs($this->person)->post($this->data.'/export')->assertSessionHas('status');
    });
});
