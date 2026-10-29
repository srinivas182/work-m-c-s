<?php

declare(strict_types=1);

namespace Wurk\Access\Contracts;

/** Which branches a person is limited to in the current business (null = every branch). */
interface BranchRestriction
{
    /** @return list<string>|null */
    public function branchRestriction(string $identityId): ?array;
}
