<?php

declare(strict_types=1);

namespace Wurk\Identity\Listeners;

use Wurk\Identity\Contracts\Invitations;
use Wurk\Tenancy\Events\OwnerInvitationRequested;

final readonly class InviteBusinessOwner
{
    public function __construct(private Invitations $invitations) {}

    public function handle(OwnerInvitationRequested $event): void
    {
        $this->invitations->inviteMember($event->tenantId, $event->businessName, $event->contact, 'owner', $event->invitedBy, $event->firstName, $event->lastName);
    }
}
