<?php

declare(strict_types=1);

namespace Tests\Support;

use Wurk\Identity\Mfa\Totp;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentityMfa;
use Wurk\Identity\Models\Membership;
use Wurk\Identity\Models\PlatformStaff;
use Wurk\Tenancy\Models\Tenant;

trait InteractsWithIdentity
{
    protected function useFakeOtpChannel(): void
    {
        FakeOtpChannel::reset();
        config(['wurk.identity.otp.channels' => ['email' => FakeOtpChannel::class, 'phone' => FakeOtpChannel::class]]);
    }

    /** @param  array<string, mixed>  $attributes */
    protected function person(array $attributes = []): Identity
    {
        return Identity::query()->create([
            'first_name' => 'Thandi',
            'last_name' => 'Mokoena',
            'email' => 'thandi@glow.co.za',
            'phone' => '+27821234567',
            ...$attributes,
        ]);
    }

    protected function staff(?string $mfaSecret = null): Identity
    {
        $identity = $this->person(['email' => 'ops@wurk.co.za', 'phone' => null]);
        PlatformStaff::query()->create(['identity_id' => $identity->id, 'status' => 'active']);
        if ($mfaSecret !== null) {
            $this->enableMfa($identity, $mfaSecret);
        }

        return $identity;
    }

    protected function enableMfa(Identity $identity, string $secret, array $recoveryHashes = []): void
    {
        IdentityMfa::query()->create([
            'identity_id' => $identity->id, 'secret' => $secret, 'confirmed_at' => now(), 'recovery_codes' => $recoveryHashes,
        ]);
    }

    protected function member(Identity $identity, Tenant $tenant): void
    {
        Membership::query()->create(['identity_id' => $identity->id, 'tenant_id' => $tenant->id, 'kind' => Membership::KIND_STAFF, 'status' => 'active']);
    }

    protected function totpNow(string $secret): string
    {
        return Totp::code($secret, now()->getTimestamp());
    }

    protected function app(string $path): string
    {
        return 'http://'.config('wurk.domains.app').$path;
    }

    protected function admin(string $path): string
    {
        return 'http://'.config('wurk.domains.admin').$path;
    }
}
