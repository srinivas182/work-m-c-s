<?php

declare(strict_types=1);

namespace Wurk\Identity\Contracts;

/** When anyone on a business's team last signed in (Sprint 15.6 — WURK's "at risk" metric). */
interface TeamActivity
{
    /**
     * @param  list<string>  $tenantIds
     * @return array<string, string|null> tenant id => latest sign-in by any active staff member (null = never)
     */
    public function lastSignIn(array $tenantIds): array;
}
