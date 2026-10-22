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
}
