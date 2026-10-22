<?php

declare(strict_types=1);

namespace Wurk\Identity\People;

use Wurk\Identity\Contracts\PeopleDirectory;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\IdentitySession;
use Wurk\Identity\Models\PlatformStaff;

final class EloquentPeopleDirectory implements PeopleDirectory
{
    public function staff(): array
    {
        return array_values(PlatformStaff::query()->get()->map(function (PlatformStaff $s): ?array {
            $identity = Identity::query()->find($s->identity_id);

            return $identity === null ? null : [
                'id' => $identity->id,
                'name' => $identity->fullName(),
                'email' => $identity->email,
                'active' => $s->status === 'active',
                'mfa' => $identity->hasMfa(),
                'last_login' => $identity->last_login_at?->toIso8601String(),
            ];
        })->filter()->sortBy('name')->values()->all());
    }

    public function staffCount(): int
    {
        return PlatformStaff::query()->where('status', 'active')->count();
    }

    public function contacts(array $ids): array
    {
        return Identity::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Identity $i) => [
            $i->id => ['name' => $i->fullName(), 'email' => $i->email, 'phone' => $i->phone],
        ])->all();
    }

    public function hasMfa(string $identityId): bool
    {
        return Identity::query()->find($identityId)?->hasMfa() ?? false;
    }

    public function isActiveStaff(string $identityId): bool
    {
        return PlatformStaff::query()->whereKey($identityId)->where('status', 'active')->exists();
    }

    public function names(array $ids): array
    {
        return Identity::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Identity $i) => [$i->id => $i->fullName()])->all();
    }

    public function deactivateStaff(string $identityId): void
    {
        PlatformStaff::query()->whereKey($identityId)->update(['status' => 'disabled']);
        IdentitySession::query()->where('identity_id', $identityId)->where('surface', 'admin')->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function reactivateStaff(string $identityId): void
    {
        PlatformStaff::query()->whereKey($identityId)->update(['status' => 'active']);
    }
}
