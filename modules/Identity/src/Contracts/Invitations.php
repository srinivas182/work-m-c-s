<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

/** Invite people by email: WURK staff, or a business team member. */
interface Invitations
{
    public function inviteStaff(string $email, string $role, ?string $invitedBy, ?string $firstName = null, ?string $lastName = null): void;

    public function inviteMember(string $tenantId, string $businessName, string $email, string $role, ?string $invitedBy, ?string $firstName = null, ?string $lastName = null): void;

    /** @return list<array{id: string, email: string, role: string, expires_at: string}> open staff invitations */
    public function pendingStaff(): array;

    /**
     * Open invitations to a business's team.
     *
     * @return list<array{id: string, email: string, role: string, expires_at: string}>
     */
    public function pendingMembers(string $tenantId): array;

    public function revoke(string $invitationId): void;
}
