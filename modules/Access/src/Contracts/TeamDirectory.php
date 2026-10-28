<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

interface TeamDirectory
{
    /**
     * The current business's team members, by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function members(): array;

    /** Add a team member to the current business (seat limits and roles apply); returns the member id. */
    public function add(string $identityId, string $displayName, string $role): string;

    /**
     * Email addresses of the current business's active team members who have a permission (by the same
     * rule as every screen) — e.g. who to alert about a new order.
     *
     * @return list<string>
     */
    public function emailsWithPermission(string $permission): array;
}
