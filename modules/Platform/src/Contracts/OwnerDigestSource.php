<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

use Carbon\CarbonImmutable;

/**
 * A module's part of the owner's evening WhatsApp summary (Sprint 21.6): its figures, and requests that need the owner's
 * yes or no — which the owner can answer by replying with a number. Runs inside the business.
 */
interface OwnerDigestSource
{
    /** Figures this module reports, by these keys: tomorrow_bookings, new_orders, received_cents, chats_waiting. */
    public const FIGURES = ['tomorrow_bookings', 'new_orders', 'received_cents', 'chats_waiting'];

    /** Short and unique, e.g. "sale"; it prefixes request references. */
    public function key(): string;

    /**
     * @param  CarbonImmutable  $today  the start of today, in the business's time zone
     * @return array<string, int> some of FIGURES
     */
    public function figures(CarbonImmutable $today): array;

    /**
     * Requests waiting for the owner's yes or no, oldest first (a few words each: "Approve Thandi's R1 450.00 sale").
     *
     * @return list<array{id: string, label: string}>
     */
    public function requests(): array;

    /**
     * Say yes or no to one of them, as the owner. Returns what happened in plain words; throws OwnerDigestRefused
     * when it can't be done (already decided, no longer allowed).
     */
    public function decide(string $id, bool $yes, string $identityId, string $name): string;
}
