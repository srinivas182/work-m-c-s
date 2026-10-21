<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Hash;
use Tests\Support\FakeOtpChannel;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentityMfa;
use Wurk\Identity\Models\Membership;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(fn () => $this->useFakeOtpChannel());

it('resets a forgotten password with a one-time code', function () {
    $person = $this->person(['password' => 'a very good passphrase']);

    $this->post($this->app('/forgot-password'), ['identifier' => 'thandi@glow.co.za'])->assertRedirect(route('identity.forgot.code'));
    $this->post($this->app('/forgot-password/code'), ['code' => FakeOtpChannel::$codes['thandi@glow.co.za']])->assertRedirect(route('identity.forgot.new'));
    $this->post($this->app('/forgot-password/new'), ['password' => 'qwertyuiop', 'password_confirmation' => 'qwertyuiop'])->assertSessionHasErrors('password');
    $this->post($this->app('/forgot-password/new'), ['password' => 'sunny days in durban', 'password_confirmation' => 'sunny days in durban'])
        ->assertRedirect(route('identity.sign-in'));

    expect(Hash::check('sunny days in durban', $person->fresh()->password))->toBeTrue();
    $this->assertGuest();
});

it('will not set a new password without a verified code', function () {
    $this->person();
    $this->get($this->app('/forgot-password/new'))->assertRedirect(route('identity.forgot'));
    $this->post($this->app('/forgot-password/new'), ['password' => 'sunny days in durban', 'password_confirmation' => 'sunny days in durban'])
        ->assertSessionHasErrors('password');
});

it('creates staff and team members from the command line', function () {
    $this->createShards(1);
    $this->createTenant('glow-salon');

    $this->artisan('identities:create', ['email' => 'Ops@WURK.co.za', '--staff' => true])->assertSuccessful();
    $this->artisan('identities:create', ['email' => 'owner@glow.co.za', '--tenant' => ['glow-salon'], '--password' => 'sunny days in durban'])->assertSuccessful();
    $this->artisan('identities:create', ['email' => 'weak@glow.co.za', '--password' => 'password1'])->assertFailed();

    expect(Identity::query()->where('email', 'ops@wurk.co.za')->firstOrFail()->isPlatformStaff())->toBeTrue()
        ->and(Membership::query()->count())->toBe(1);
});

it('refuses a pre-set authenticator secret in production', function () {
    app()->detectEnvironment(fn () => 'production');
    $this->artisan('identities:create', ['email' => 'x@wurk.co.za', '--mfa-secret' => 'JBSWY3DPEHPK3PXP'])->assertFailed();
    expect(IdentityMfa::query()->count())->toBe(0);
});
