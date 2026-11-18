<?php

declare(strict_types=1);

namespace Wurk\Sales\Contracts;

/** In-store sales recorded between two moments, by method, after refunds (Sprint 23.5b: the till's cash-up). */
interface SalesTotals
{
    /**
     * @return array<string, int> cents by method (cash, card, eft, account)
     */
    public function totals(\DateTimeInterface $from, \DateTimeInterface $to, ?string $branchId = null): array;
}
