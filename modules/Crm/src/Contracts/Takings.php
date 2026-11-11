<?php

declare(strict_types=1);

namespace Wurk\Crm\Contracts;

/** What the business took in a period — shop, bookings and in-store sales, net of refunds (Sprint 15.1b). */
interface Takings
{
    /** @param list<string>|null $branchIds  null = every branch */
    public function net(\DateTimeInterface $from, \DateTimeInterface $to, ?array $branchIds): int;

    /**
     * Net paid (after refunds) for particular orders, bookings or sales — e.g. those a campaign's coupon was used on.
     *
     * @param  list<array{0: string, 1: string}>  $sources  [source_type, source_id]
     */
    public function paidFor(array $sources): int;
}
