<?php

declare(strict_types=1);

namespace Wurk\Payments\Contracts;

/**
 * Money taken in person (Sprint 22.3): cash, the card machine, or SnapScan / Zapper at the counter. Recorded against a
 * booking, class place or shop order, with who took it; audited, and counted in the day's takings.
 */
interface InPersonPayments
{
    public const METHODS = ['cash' => 'Cash', 'card_machine' => 'Card machine', 'qr' => 'SnapScan / Zapper at the counter'];

    public function record(string $sourceType, string $sourceId, string $method, int $cents, ?string $takenBy, string $takenByName, ?string $branchId): void;

    /** Cents taken in person for one booking, class place or order. */
    public function takenFor(string $sourceType, string $sourceId): int;

    /**
     * Cents taken in person for several bookings, class places or orders at once.
     *
     * @param  list<string>  $sourceIds
     * @return array<string, int> by id (none taken: left out)
     */
    public function takenForMany(string $sourceType, array $sourceIds): array;

    /**
     * Cents taken in person between two moments, by method (cash, card_machine, qr).
     *
     * @return array<string, int>
     */
    public function totals(\DateTimeInterface $from, \DateTimeInterface $to): array;
}
