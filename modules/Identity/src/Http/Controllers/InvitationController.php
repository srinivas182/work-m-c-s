<?php

declare(strict_types=1);

namespace Wurk\Identity\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Wurk\Identity\Auth\SessionRegistry;
use Wurk\Identity\Auth\SignInFlow;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Events\StaffGranted;
use Wurk\Identity\Models\Identity;
use Wurk\Identity\Models\Invitation;
use Wurk\Identity\Models\Membership;
use Wurk\Identity\Models\PlatformStaff;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * Accepting an invitation proves control of the invited email address (the
 * link was sent there), so it counts as the first sign-in factor. WURK staff
 * then set up an authenticator like any other staff sign-in.
 */
final class InvitationController
{
    public function __construct(private readonly SignInFlow $flow, private readonly SessionRegistry $sessions) {}

    public function show(Request $request, string $token, TenantDirectory $directory): Response
    {
        $invitation = $this->find($token);
        $existing = $invitation === null ? null : Identity::query()->where('email', $invitation->email)->first();

        return Inertia::render('Identity::Invitation', [
            'valid' => $invitation !== null,
            'joining' => $invitation === null ? null : ($invitation->tenant_id ? $directory->find($invitation->tenant_id)?->displayName() : 'WURK'),
            'role' => $invitation?->role,
            'email' => $invitation?->email,
            'needsName' => $invitation !== null && $existing === null,
            'firstName' => $invitation?->first_name,
            'lastName' => $invitation?->last_name,
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->find($token);
        abort_if($invitation === null, 410, 'This invitation has expired or was already used.');

        $existing = Identity::query()->where('email', $invitation->email)->first();
        $data = $request->validate($existing === null ? [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
        ] : []);

        // Someone else signed in on this device: sign them out first.
        if (Auth::check() && Auth::id() !== $existing?->id) {
            $this->sessions->end($request);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $identity = DB::transaction(function () use ($invitation, $existing, $data): Identity {
            $identity = $existing ?? Identity::query()->create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'] ?? '',
                'email' => $invitation->email,
            ]);
            $identity->forceFill(['email_verified_at' => $identity->email_verified_at ?? now()])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();

            if ($invitation->kind === Invitation::KIND_STAFF) {
                PlatformStaff::query()->updateOrCreate(['identity_id' => $identity->id], ['status' => 'active']);
            } else {
                Membership::query()->updateOrCreate(
                    ['identity_id' => $identity->id, 'tenant_id' => $invitation->tenant_id, 'kind' => Membership::KIND_STAFF],
                    ['status' => 'active'],
                );
            }

            return $identity;
        });

        if ($invitation->kind === Invitation::KIND_STAFF) {
            event(new StaffGranted($identity->id, $invitation->role));
        } else {
            event(new MembershipGranted($identity->id, (string) $invitation->tenant_id, Membership::KIND_STAFF, $identity->fullName(), $invitation->role));
        }

        return $this->flow->afterFirstFactor($request, $identity, false, 'invitation');
    }

    private function find(string $token): ?Invitation
    {
        $invitation = Invitation::query()->where('token_hash', Invitation::hashToken($token))->first();

        return $invitation?->isOpen() ? $invitation : null;
    }
}
