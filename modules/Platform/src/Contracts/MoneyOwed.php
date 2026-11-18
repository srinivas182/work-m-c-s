<?php

declare(strict_types=1);

namespace Wurk\Platform\Contracts;

/**
 * Money customers owe the current business (Sprint 23.5), gathered from each module: unpaid deposits (Bookings),
 * pay-later orders (Shop), sales on account (Sales) and invoices (Money). Each source runs inside the business.
 */
interface MoneyOwed
{
    /**
     * A source returns items with: key, kind, title, customer, phone|null, email|null, amount_cents, since (Y-m-d), due (Y-m-d|null),
     * link (a path in the back office) and, optionally, settle (where to POST {how: cash|card_machine|qr|eft, to: paid} when it's paid
     * in person or by EFT).
     *
     * @param  \Closure(): iterable<array<string, mixed>>  $source
     */
    public function register(string $module, \Closure $source): void;

    /**
     * Everything owed, oldest due first.
     *
     * @return list<array{key: string, kind: string, title: string, customer: string, phone: string|null, email: string|null,
     *   amount_cents: int, since: string, due: string|null, link: string, settle?: string, module: string}>
     */
    public function all(): array;
}
