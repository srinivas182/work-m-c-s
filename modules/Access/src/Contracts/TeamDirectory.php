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

    /**
     * Everyone on the team, with their role and branches (an empty list with all_branches = true means every branch).
     *
     * @return list<array{id: string, identity_id: string, name: string, role: string, all_branches: bool, branches: list<string>, active: bool}>
     */
    public function roster(): array;

    /**
     * The business roles a member can have.
     *
     * @return list<array{key: string, name: string, branch_restricted: bool}>
     */
    public function roles(): array;

    /** Give a member one role (replacing their others). Never leaves the business without an owner. */
    public function setRole(string $memberId, string $role): void;

    /**
     * Limit a member to some branches, or null for every branch.
     *
     * @param  list<string>|null  $branchIds
     */
    public function setBranches(string $memberId, ?array $branchIds): void;

    /** Remove a member's access (their history is kept). Never the last owner. */
    public function deactivate(string $memberId): void;
}
