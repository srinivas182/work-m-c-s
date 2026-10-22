<?php

declare(strict_types=1);

namespace Wurk\Identity\Onboarding;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Contracts\Accounts;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Membership;
use Wurk\Identity\Support\Destination;

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
}
