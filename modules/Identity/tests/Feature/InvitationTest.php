<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Identity\Mail\InvitationMail;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Invitation;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

beforeEach(function () {
    Mail::fake();
    $this->createShards(1);
    $this->glow = $this->createTenant('glow-salon', name: 'Glow Salon');
});

function sentInvitationPath(): string
{
    $url = null;
    Mail::assertSent(InvitationMail::class, function (InvitationMail $m) use (&$url) {
        $url = $m->url;

        return true;
    });

    return (string) parse_url((string) $url, PHP_URL_PATH);
}

it('lets a new person accept a business invitation and land in the business as owner', function () {
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'thandi@glow.co.za', 'owner', null, 'Thandi');
    $path = sentInvitationPath();

    $this->get($this->app($path))->assertInertia(fn (Assert $p) => $p->component('Identity::Invitation')->where('joining', 'Glow Salon')->where('needsName', true));
    $this->post($this->app($path), ['first_name' => 'Thandi', 'last_name' => 'Mokoena'])->assertRedirect(route('identity.businesses'));

    $person = Identity::query()->where('email', 'thandi@glow.co.za')->firstOrFail();
    $this->assertAuthenticatedAs($person);
    expect($person->email_verified_at)->not->toBeNull()
        ->and(app(TenantContext::class)->run($this->glow, fn () => Gate::forUser($person)->allows('team.manage')))->toBeTrue();
    $this->get($this->app('/glow-salon'))->assertOk();
});

it('works only once, never after expiry, and stores only a hash of the token', function () {
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'thandi@glow.co.za', 'staff', null);
    $path = sentInvitationPath();
    $token = basename($path);

    expect(Invitation::query()->value('token_hash'))->not->toBe($token)->toHaveLength(64);

    $this->post($this->app($path), ['first_name' => 'Thandi']);
    $this->post($this->app('/sign-out'));
    $this->post($this->app($path), ['first_name' => 'Thandi'])->assertStatus(410);

    Mail::fake();
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'late@glow.co.za', 'staff', null);
    $late = sentInvitationPath();
    $this->travel(8)->days();
    $this->get($this->app($late))->assertInertia(fn (Assert $p) => $p->where('valid', false));
});

it('replaces an earlier invitation when a new one is sent', function () {
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'thandi@glow.co.za', 'staff', null);
    $first = sentInvitationPath();
    Mail::fake();
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'thandi@glow.co.za', 'owner', null);

    $this->get($this->app($first))->assertInertia(fn (Assert $p) => $p->where('valid', false));
});

it('adds an existing WURK ID without asking for a name again', function () {
    $this->person(['email' => 'thandi@glow.co.za', 'phone' => null]);
    app(Invitations::class)->inviteMember($this->glow->id, 'Glow Salon', 'thandi@glow.co.za', 'staff', null);
    $path = sentInvitationPath();

    $this->get($this->app($path))->assertInertia(fn (Assert $p) => $p->where('needsName', false));
    $this->post($this->app($path))->assertRedirect(route('identity.businesses'));
    expect(Identity::query()->where('email', 'thandi@glow.co.za')->count())->toBe(1);
});

it('sends invited WURK staff through authenticator set-up before they are signed in', function () {
    app(Invitations::class)->inviteStaff('newops@wurk.co.za', 'support', null, 'Lerato');
    $path = sentInvitationPath();

    $this->post($this->admin($path), ['first_name' => 'Lerato'])->assertRedirect(route('identity.mfa.setup'));
    $this->assertGuest();
    expect(Identity::query()->where('email', 'newops@wurk.co.za')->firstOrFail()->isPlatformStaff())->toBeTrue();
});
