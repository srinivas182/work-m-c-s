<?php

declare(strict_types=1);

use Tests\Support\InteractsWithIdentity;
use Tests\Support\InteractsWithShards;
use Wurk\Access\Actions\Team;
use Wurk\Audit\Models\PlatformAuditEvent;
use Wurk\Identity\Mfa\Totp;
use Wurk\Security\Support\Settings;
use Wurk\Tenancy\Contracts\TenantContext;

uses(InteractsWithIdentity::class, InteractsWithShards::class);

it('limits the admin to allowed networks once a list is set, without touching business sites', function () {
    $admin = $this->staffWithRole('super-admin');
    $office = ['REMOTE_ADDR' => '196.25.10.7'];

    $this->actingAs($admin)->put($this->admin('/security'), ['allowlist' => ['196.25.10.0/24']], $office)->assertSessionHas('status');

    $this->actingAs($admin)->get($this->admin('/dashboard'), $office)->assertOk();
    $this->actingAs($admin)->get($this->admin('/dashboard'), ['REMOTE_ADDR' => '41.13.0.9'])->assertForbidden();
    $this->get($this->admin('/sign-in'), ['REMOTE_ADDR' => '41.13.0.9'])->assertForbidden();
    expect($this->get($this->app('/sign-in'), ['REMOTE_ADDR' => '41.13.0.9'])->status())->not->toBe(403); // business back office unaffected

    config(['wurk.security.admin_ip_allowlist' => false]); // emergency switch
    $this->actingAs($admin)->get($this->admin('/dashboard'), ['REMOTE_ADDR' => '41.13.0.9'])->assertOk();
});

it('refuses a list that would lock out the person saving it, or entries that are not addresses', function () {
    $admin = $this->staffWithRole('super-admin');

    $this->actingAs($admin)->put($this->admin('/security'), ['allowlist' => ['10.0.0.0/8']], ['REMOTE_ADDR' => '196.25.10.7'])->assertSessionHasErrors('allowlist');
    $this->actingAs($admin)->put($this->admin('/security'), ['allowlist' => ['not-an-ip']], ['REMOTE_ADDR' => '196.25.10.7'])->assertSessionHasErrors('allowlist.0');
    expect(app(Settings::class)->platform('admin_ip_allowlist', []))->toBe([]);
});

it('stores cash-payment recipients and records the change', function () {
    $admin = $this->staffWithRole('super-admin');
    $finance = $this->staffWithRole('finance', 'finance@wurk.co.za');

    $this->actingAs($admin)->put($this->admin('/security'), ['cash_otp_recipients' => [$finance->id], 'cash_receipt_recipients' => ['finance@wurk.co.za']])->assertSessionHas('status');
    $this->actingAs($admin)->put($this->admin('/security'), ['cash_otp_recipients' => ['01NOTSTAFF0000000000000000']])->assertSessionHasErrors('cash_otp_recipients.0');

    expect(app(Settings::class)->platform('cash_otp_recipients'))->toBe([$finance->id])
        ->and(PlatformAuditEvent::query()->where('action', 'security.platform_settings_changed')->count())->toBe(1);
});

it('lets owners require an authenticator for the team, and sends members without one to set it up', function () {
    $this->createShards(1);
    $glow = $this->createTenant('glow-salon');
    $context = app(TenantContext::class);
    $owner = $this->person();
    $desk = $this->person(['email' => 'desk@glow.co.za', 'phone' => null]);
    foreach ([[$owner, 'owner'], [$desk, 'staff']] as [$who, $role]) {
        $this->member($who, $glow);
        $context->run($glow, fn () => app(Team::class)->add($who->id, 'X', $role));
    }

    $this->actingAs($owner)->put($this->app('/glow-salon/settings/security'), ['require_mfa' => true])->assertSessionHasErrors('require_mfa');

    $this->enableMfa($owner, Totp::generateSecret());
    $this->actingAs($owner)->put($this->app('/glow-salon/settings/security'), ['require_mfa' => true])->assertSessionHas('status');

    $this->actingAs($owner)->get($this->app('/glow-salon'))->assertOk();
    $this->actingAs($desk)->get($this->app('/glow-salon'))->assertRedirect(route('identity.account'))->assertSessionHas('warning');

    $this->enableMfa($desk, Totp::generateSecret());
    $this->actingAs($desk)->get($this->app('/glow-salon'))->assertOk();
    $this->actingAs($desk)->get($this->app('/glow-salon/settings/security'))->assertNotFound();
});
