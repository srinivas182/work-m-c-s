<?php

declare(strict_types=1);

namespace Wurk\Identity\Invites;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Wurk\Identity\Contracts\Invitations;
use Wurk\Identity\Mail\InvitationMail;
use Wurk\Identity\Models\Invitation;
use Wurk\Identity\Support\Destination;

final class InvitationService implements Invitations
{
    public const TTL_DAYS = 7;

    public function inviteStaff(string $email, string $role, ?string $invitedBy, ?string $firstName = null, ?string $lastName = null): void
    {
        $this->send(Invitation::KIND_STAFF, null, 'WURK', $email, $role, $invitedBy, $firstName, $lastName);
    }

    public function inviteMember(string $tenantId, string $businessName, string $email, string $role, ?string $invitedBy, ?string $firstName = null, ?string $lastName = null): void
    {
        $this->send(Invitation::KIND_MEMBER, $tenantId, $businessName, $email, $role, $invitedBy, $firstName, $lastName);
    }

    public function pendingStaff(): array
    {
        return array_values(Invitation::query()->where('kind', Invitation::KIND_STAFF)->whereNull('accepted_at')
            ->whereNull('revoked_at')->where('expires_at', '>', now())->latest()->get()
            ->map(fn (Invitation $i) => ['id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'expires_at' => $i->expires_at->toIso8601String()])
            ->all());
    }

    public function revoke(string $invitationId): void
    {
        Invitation::query()->whereKey($invitationId)->whereNull('accepted_at')->update(['revoked_at' => now()]);
    }

    private function send(string $kind, ?string $tenantId, string $joining, string $email, string $role, ?string $invitedBy, ?string $firstName, ?string $lastName): void
    {
        $destination = Destination::parse($email, 'email');
        if ($destination->type !== 'email') {
            throw ValidationException::withMessages(['email' => 'Invitations are sent by email for now; mobile invitations arrive with SMS in Sprint 2.3.']);
        }

        // A newer invitation to the same person for the same place replaces the old one.
        Invitation::query()->where('email', $destination->value)->where('kind', $kind)->where('tenant_id', $tenantId)
            ->whereNull('accepted_at')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $token = Str::random(48);
        $invitation = Invitation::query()->create([
            'kind' => $kind,
            'email' => $destination->value,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'tenant_id' => $tenantId,
            'role' => $role,
            'invited_by' => $invitedBy,
            'token_hash' => Invitation::hashToken($token),
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        $url = $kind === Invitation::KIND_STAFF
            ? 'https://'.config('wurk.domains.admin').'/invitations/'.$token
            : 'https://'.config('wurk.domains.app').'/invitations/'.$token;

        Mail::to($destination->value)->send(new InvitationMail($joining, $invitation->role, $url, $firstName));
    }
}
