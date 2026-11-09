<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** What the business took in a period — shop, bookings and in-store sales, net of refunds (Sprint 15.1b). */
interface Takings
{
    /** @param list<string>|null $branchIds  null = every branch */
    public function net(\DateTimeInterface $from, \DateTimeInterface $to, ?array $branchIds): int;
}
