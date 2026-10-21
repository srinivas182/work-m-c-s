<?php

declare(strict_types=1);

namespace Wurk\Access\Listeners;

use Wurk\Access\Actions\PlatformAccess;
use Wurk\Access\Actions\Team;
use Wurk\Access\Roles\SystemRoles;
use Wurk\Identity\Events\MembershipGranted;
use Wurk\Identity\Events\StaffGranted;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

final readonly class GrantAccessOnMembership
{
    public function __construct(
        private Team $team,
        private PlatformAccess $platform,
        private TenantContext $context,
        private TenantDirectory $directory,
    ) {}

    public function handleMembership(MembershipGranted $event): void
    {
        if ($event->kind !== 'staff') {
            return; // customers join the referral programme (Sprint 11), not the team
        }

        $tenant = $this->directory->find($event->tenantId);
        if ($tenant === null) {
            return;
        }

        $this->context->run($tenant, fn () => $this->team->add($event->identityId, $event->displayName, $event->role ?? SystemRoles::STAFF));
    }

    public function handleStaff(StaffGranted $event): void
    {
        $this->platform->grant($event->identityId, $event->role);
    }
}
