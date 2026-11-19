<?php

declare(strict_types=1);

namespace Wurk\Sales\Contracts;

/** In-store sales recorded between two moments, by method, in full (Sprint 23.5b: the till's cash-up). Refunds are counted on the day they're paid back, through Payments' Refunds (25.5). */
interface SalesTotals
{
    /**
     * @return array<string, int> cents by method (cash, card, eft, account)
     */
    public function totals(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId = null): array;
}
