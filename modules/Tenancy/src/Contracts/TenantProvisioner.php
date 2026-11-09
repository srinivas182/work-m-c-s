<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Contracts;

/** Creating businesses: web-address checks and provisioning on the least-full shard. */
interface TenantProvisioner
{
    /** Why a handle can't be used ("Already taken", "Reserved word"…), or null if it's free. */
    public function handleProblem(string $handle): ?string;

    public function provision(string $name, string $handle, string $country): CurrentTenant;

    /** Mark or unmark a business as an agent's demo (Sprint 14.8). */
    public function markDemo(string $tenantId, bool $demo): void;

    /** Archive a business: site offline, address freed (handle and subdomain become archived-<id>), status deleted. */
    public function archive(string $tenantId): void;

    /** A signed link that lets someone claim a demo through signup, valid until the given time (Sprint 14.8b). */
    public function claimToken(string $tenantId, \DateTimeInterface $until): string;

    /** The demo a claim link is for — null if forged, expired, or no longer an active demo. */
    public function claimable(string $token): ?CurrentTenant;

    /**
     * Turn a demo into a live business: its new name and address (a different address must be free), demo restrictions lifted.
     *
     * @throws \DomainException the address is taken, or it's no longer a demo
     */
    public function claim(string $tenantId, string $name, string $handle): CurrentTenant;
}
