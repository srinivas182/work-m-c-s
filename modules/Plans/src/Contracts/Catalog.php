<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

/** Packages on sale, and starting a business on one. */
interface Catalog
{
    /**
     * Public packages on sale, in display order (prices in cents, excl. VAT).
     *
     * @return list<array{id: string, key: string, name: string, description: string, type: string, monthly_cents: int, yearly_cents: int, trial_days: int, staff_limit: int, multi_branch: bool, max_branches: int, custom_domain: bool, monthly_credits: int, modules: list<string>}>
     */
    public function onSale(): array;

    /**
     * Puts a business on a package's current terms. A trial starts only when
     * $allowTrial is true and the package offers one; otherwise the
     * subscription waits for its first payment.
     *
     * @return array{status: string, trial_ends_at: string|null}
     */
    public function subscribe(string $tenantId, string $packageId, string $period, bool $allowTrial): array;

    /**
     * A business's subscription with the price of one billing period (cents, excl. VAT).
     *
     * @return array{package: string, package_key: string, version: int, status: string, period: string, period_cents: int, monthly_credits: int, trial_ends_at: string|null, current_period_end: string|null}|null
     */
    public function subscriptionOf(string $tenantId): ?array;

    /** A payment covered a period: the subscription becomes active until $periodEnd. */
    public function markPaid(string $tenantId, \DateTimeInterface $periodStart, \DateTimeInterface $periodEnd): void;

    /** A renewal payment failed (dunning picks it up). */
    public function markPastDue(string $tenantId): void;

    /** @return list<string> ids of active businesses whose paid period ends before $before */
    public function dueForRenewal(\DateTimeInterface $before): array;
}
