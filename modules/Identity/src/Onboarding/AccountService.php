<?php

declare(strict_types=1);

namespace Wurk\Identity\Onboarding;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Membership;
use Wurk\Identity\Support\Destination;
use Wurk\Identity\Support\PasswordPolicy;

final readonly class AccountService implements Accounts
{
    public function __construct(private SignInFlow $flow) {}

    public function forVerifiedContact(string $contact, string $firstName, string $lastName): string
    {
        $destination = Destination::parse($contact);
        $column = $destination->column();
        $identity = Identity::query()->where($column, $destination->value)->first()
            ?? Identity::query()->create(['first_name' => $firstName, 'last_name' => $lastName, $column => $destination->value]);

        $verified = $destination->type === 'email' ? 'email_verified_at' : 'phone_verified_at';
        $identity->forceFill([$verified => $identity->{$verified} ?? now()])->save();

        return $identity->id;
    }

    public function makeOwner(string $identityId, string $tenantId, string $displayName): void
    {
        Membership::query()->updateOrCreate(
            ['identity_id' => $identityId, 'tenant_id' => $tenantId, 'kind' => Membership::KIND_STAFF],
            ['status' => 'active'],
        );
        event(new MembershipGranted($identityId, $tenantId, Membership::KIND_STAFF, $displayName, 'owner'));
    }

    public function signIn(Request $request, string $identityId, string $intended): RedirectResponse
    {
        $request->session()->put('url.intended', $intended);

        return $this->flow->afterFirstFactor($request, Identity::query()->findOrFail($identityId), false, 'signup');
    }

    public function hasPassword(string $identifier): bool
    {
        $d = Destination::parse($identifier);
        $i = Identity::query()->where($d->column(), $d->value)->first();

        return $i !== null && $i->isActive() && $i->password !== null;
    }

    public function checkPassword(string $identifier, string $password, ?string $ip): ?string
    {
        $d = Destination::parse($identifier);
        $key = 'pwd:'.$d->hash().'|'.$ip;
        if (RateLimiter::tooManyAttempts($key, (int) config('wurk.identity.password.max_attempts', 5))) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            throw ValidationException::withMessages(['password' => "Too many attempts. Try again in {$minutes} minutes, or sign in with a code."]);
        }
        $i = Identity::query()->where($d->column(), $d->value)->first();
        if ($i === null || $i->password === null || ! $i->isActive() || ! Hash::check($password, $i->password)) {
            RateLimiter::hit($key, (int) config('wurk.identity.password.lockout_seconds', 900));

            return null;
        }
        RateLimiter::clear($key);

        return $i->id;
    }

    public function setPassword(string $identityId, string $password): void
    {
        Identity::query()->findOrFail($identityId)->forceFill(['password' => $password])->save();
    }

    public function passwordRules(): array
    {
        return array_values(PasswordPolicy::rules());
    }
}
