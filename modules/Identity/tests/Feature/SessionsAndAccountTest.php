<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Wurk\Identity\Auth\SessionRegistry;
use Wurk\Identity\Mfa\Totp;
use Wurk\Identity\Models\IdentitySession;

uses(InteractsWithIdentity::class);

function signInWithPassword($test, string $url): void
{
    $test->post($url, ['identifier' => 'thandi@glow.co.za', 'password' => 'a very good passphrase']);
}

beforeEach(function () {
    $this->person = $this->person(['password' => 'a very good passphrase']);
});

it('signs back-office users out after 2 hours idle unless they chose to be remembered', function () {
    signInWithPassword($this, $this->app('/sign-in/password'));
    $this->get($this->app('/account/security'))->assertOk();

    $this->travel(121)->minutes();
    $this->get($this->app('/account/security'))->assertRedirect(route('identity.sign-in'))
        ->assertSessionHas('status', 'For your security you were signed out after a period of inactivity.');
    $this->assertGuest();
});

it('signs WURK staff out after 30 minutes idle and 8 hours in total', function () {
    $secret = Totp::generateSecret();
    $staff = $this->staff($secret);
    $staff->update(['password' => 'a very good passphrase']);

    $this->post($this->admin('/sign-in/password'), ['identifier' => 'ops@wurk.co.za', 'password' => 'a very good passphrase']);
    $this->post($this->admin('/sign-in/mfa'), ['code' => Totp::code($secret, now()->getTimestamp())]);
    $this->get($this->admin('/dashboard'))->assertOk();

    $this->travel(29)->minutes();
    $this->get($this->admin('/dashboard'))->assertOk();
    $this->travel(31)->minutes();
    $this->get($this->admin('/dashboard'))->assertRedirect(route('identity.sign-in'));
});

it('lists signed-in devices and signs out the others', function () {
    signInWithPassword($this, $this->app('/sign-in/password'));
    $other = IdentitySession::query()->create([
        'id' => SessionRegistry::key('other-device-session'), 'identity_id' => $this->person->id, 'surface' => 'app',
        'ip' => '41.0.0.9', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0', 'last_active_at' => now(),
    ]);

    $this->get($this->app('/account/security'))->assertInertia(fn (Assert $p) => $p
        ->component('Identity::Security')->has('sessions', 2)
        ->where('sessions', fn ($s) => collect($s)->contains('device', 'Chrome on Android')));

    $this->delete($this->app('/account/sessions'))->assertSessionHas('status', 'Signed out of 1 other device.');

    expect($other->fresh()->revoked_at)->not->toBeNull();
});

it('signs out a device whose session was revoked elsewhere', function () {
    signInWithPassword($this, $this->app('/sign-in/password'));
    IdentitySession::query()->where('identity_id', $this->person->id)->update(['revoked_at' => now()]);

    $this->get($this->app('/account/security'))->assertRedirect(route('identity.sign-in'))
        ->assertSessionHas('status', 'You were signed out from another device.');
});

it('changes the password, requires the current one, and enforces the policy', function () {
    $this->actingAs($this->person);

    $this->put($this->app('/account/password'), ['current_password' => 'wrong', 'password' => 'another good phrase', 'password_confirmation' => 'another good phrase'])
        ->assertSessionHasErrors('current_password');
    $this->put($this->app('/account/password'), ['current_password' => 'a very good passphrase', 'password' => 'password123', 'password_confirmation' => 'password123'])
        ->assertSessionHasErrors('password');
    $this->put($this->app('/account/password'), ['current_password' => 'a very good passphrase', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertSessionHasErrors('password');
    $this->put($this->app('/account/password'), ['current_password' => 'a very good passphrase', 'password' => 'another good phrase', 'password_confirmation' => 'another good phrase'])
        ->assertSessionHasNoErrors();
});

it('lets an owner turn the authenticator on and off, but never WURK staff', function () {
    $this->actingAs($this->person);
    $this->post($this->app('/account/mfa'));
    $secret = $this->person->mfa()->firstOrFail()->secret;

    $this->post($this->app('/account/mfa/confirm'), ['code' => Totp::code($secret, now()->getTimestamp())])->assertSessionHas('mfa.recovery_codes');
    expect($this->person->fresh()->hasMfa())->toBeTrue();

    $this->travel(31)->seconds();
    $this->delete($this->app('/account/mfa'), ['code' => Totp::code($secret, now()->getTimestamp())])->assertSessionHasNoErrors();
    expect($this->person->fresh()->hasMfa())->toBeFalse();

    $staffSecret = Totp::generateSecret();
    $this->actingAs($this->staff($staffSecret))
        ->delete($this->admin('/account/mfa'), ['code' => Totp::code($staffSecret, now()->getTimestamp())])->assertSessionHasErrors('mfa');
});

it('updates the profile name', function () {
    $this->actingAs($this->person)->put($this->app('/account/profile'), ['first_name' => 'Thandeka', 'last_name' => 'M'])
        ->assertSessionHas('status', 'Profile saved.');
    expect($this->person->fresh()->fullName())->toBe('Thandeka M');
});

it('signs out', function () {
    signInWithPassword($this, $this->app('/sign-in/password'));
    $this->post($this->app('/sign-out'))->assertRedirect(route('identity.sign-in'));
    $this->assertGuest();
});
