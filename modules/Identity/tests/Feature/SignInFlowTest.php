<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Identity\Mfa\RecoveryCodes;
use Wurk\Identity\Mfa\Totp;
use Wurk\Identity\Models\IdentityMfa;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    $this->useFakeOtpChannel();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
});

it('signs a team member in with a code and takes them straight to their business', function () {
    $person = $this->person();
    $this->member($person, $this->glow);

    $this->post($this->app('/sign-in/code'), ['identifier' => '082 123 4567', 'remember' => false])
        ->assertRedirect(route('identity.code'));
    $this->get($this->app('/sign-in/code'))->assertInertia(fn (Assert $p) => $p->component('Identity::Code')->where('destination', '+27 ••• ••• 4567'));

    $code = FakeOtpChannel::$codes['+27821234567'];
    $this->post($this->app('/sign-in/code/verify'), ['code' => $code])->assertRedirect(route('identity.businesses'));

    $this->assertAuthenticatedAs($person);
    $this->get($this->app('/businesses'))->assertRedirect($this->app('/glow-salon'));
    $this->get($this->app('/glow-salon'))->assertOk()->assertInertia(fn (Assert $p) => $p
        ->component('Identity::Workspace')->where('business.name', 'Glow Salon')->where('auth.user.name', 'Thandi Mokoena'));
});

it('lets people with several businesses choose one', function () {
    $person = $this->person();
    $this->member($person, $this->glow);
    $this->member($person, $this->createTenant('glow-spa', name: 'Glow Spa'));

    $this->actingAs($person)->get($this->app('/businesses'))
        ->assertInertia(fn (Assert $p) => $p->component('Identity::Businesses')->has('businesses', 2)->where('businesses.0.name', 'Glow Salon'));
});

it('rejects a wrong code with a plain message', function () {
    $this->person();
    $this->post($this->app('/sign-in/code'), ['identifier' => 'thandi@glow.co.za']);
    $code = FakeOtpChannel::$codes['thandi@glow.co.za'];

    $this->post($this->app('/sign-in/code/verify'), ['code' => $code === '000000' ? '111111' : '000000'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('responds identically for unknown people', function () {
    $this->post($this->app('/sign-in/code'), ['identifier' => 'stranger@example.com'])->assertRedirect(route('identity.code'));
    expect(FakeOtpChannel::$sent)->toBe(0);
});

it('signs in with a password and sets a 30-day remember-me cookie on the back office', function () {
    $person = $this->person(['password' => 'a very good passphrase']);
    $this->member($person, $this->glow);

    $response = $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase', 'remember' => true]);

    $response->assertRedirect(route('identity.businesses'))->assertCookie(Auth::guard('web')->getRecallerName());
    $this->assertAuthenticatedAs($person);
});

it('locks password sign-in after five failures', function () {
    $this->person(['password' => 'a very good passphrase']);

    foreach (range(1, 5) as $_) {
        $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'wrong'])
            ->assertSessionHasErrors(['password' => 'That email/number and password don’t match.']);
    }

    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase'])
        ->assertSessionHasErrors(['password']);
    $this->assertGuest();
});

it('requires a guest to sign in before any business is looked up', function () {
    $this->get($this->app('/glow-salon'))->assertRedirect(route('identity.sign-in'));
    $this->get($this->app('/no-such-business'))->assertRedirect(route('identity.sign-in'));
});

it('shows non-members the same 404 as an unknown business', function () {
    $outsider = $this->person();
    $this->actingAs($outsider)->get($this->app('/glow-salon'))->assertNotFound();
    $this->actingAs($outsider)->get($this->app('/no-such-business'))->assertNotFound();
});

it('keeps people without staff access out of the WURK admin', function () {
    $this->person(['password' => 'a very good passphrase']);

    $this->post($this->admin('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase'])
        ->assertSessionHasErrors(['identifier' => 'This account doesn’t have WURK staff access.']);
    $this->assertGuest();
});

it('makes WURK staff set up an authenticator before they are signed in', function () {
    $this->staff();
    $this->post($this->admin('/sign-in/code'), ['identifier' => 'ops@wurk.co.za']);
    $this->post($this->admin('/sign-in/code/verify'), ['code' => FakeOtpChannel::$codes['ops@wurk.co.za']])
        ->assertRedirect(route('identity.mfa.setup'));
    $this->assertGuest();

    $this->get($this->admin('/sign-in/mfa/setup'))->assertInertia(fn (Assert $p) => $p->component('Identity::MfaSetup')->has('qr'));
    $secret = IdentityMfa::query()->firstOrFail()->secret;

    $this->post($this->admin('/sign-in/mfa/setup'), ['code' => Totp::code($secret, now()->getTimestamp())])
        ->assertInertia(fn (Assert $p) => $p->component('Identity::RecoveryCodes')->has('codes', 10));
    $this->assertGuest();

    $this->post($this->admin('/sign-in/mfa/continue'))->assertRedirect(route('admin.home'));
    $this->assertAuthenticated();
    $this->get($this->admin('/dashboard'))->assertOk();
    expect(Auth::guard('web')->viaRemember())->toBeFalse();
});

it('asks for the authenticator code when MFA is on, and accepts a recovery code once', function () {
    $secret = Totp::generateSecret();
    $recovery = RecoveryCodes::generate();
    $person = $this->person(['password' => 'a very good passphrase']);
    $this->enableMfa($person, $secret, $recovery['hashed']);

    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase'])
        ->assertRedirect(route('identity.mfa'));
    $this->assertGuest();
    $this->get($this->app('/account/security'))->assertRedirect(route('identity.sign-in'));

    $this->post($this->app('/sign-in/mfa'), ['code' => $recovery['plain'][0], 'recovery' => true])->assertRedirect();
    $this->assertAuthenticatedAs($person);

    expect(IdentityMfa::query()->find($person->id)->recovery_codes)->toHaveCount(9);
});

it('rejects a wrong authenticator code', function () {
    $secret = Totp::generateSecret();
    $person = $this->person(['password' => 'a very good passphrase']);
    $this->enableMfa($person, $secret);

    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase']);
    $this->post($this->app('/sign-in/mfa'), ['code' => '123456'])->assertSessionHasErrors('code');
    $this->assertGuest();
});

it('regenerates the session id at sign-in', function () {
    $person = $this->person(['password' => 'a very good passphrase']);
    $this->get($this->app('/sign-in'));
    $before = session()->getId();

    $this->post($this->app('/sign-in/password'), ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase']);

    expect(session()->getId())->not->toBe($before)
        ->and(Hash::check('a very good passphrase', $person->fresh()->password))->toBeTrue();
});
