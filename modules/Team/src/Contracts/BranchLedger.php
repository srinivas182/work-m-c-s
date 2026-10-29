<?php

declare(strict_types=1);

namespace Wurk\Team\Contracts;

/** Records what one branch owes another (records only; settled by hand). */
interface BranchLedger
{
    /**
     * Money received into one branch's account (null = the business-wide account, i.e. the main branch) for
     * something another branch supplied. Nothing is recorded when it's the same branch. Negative = a refund.
     */
    public function record(?string $receivedBy, ?string $suppliedBy, int $cents, string $kind, string $reference, string $note): void;
}
