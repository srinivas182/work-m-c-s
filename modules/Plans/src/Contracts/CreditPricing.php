<?php

declare(strict_types=1);

namespace Wurk\Plans\Contracts;

interface CreditPricing
{
    /** What a credit costs a business today, including VAT (cheapest active pack); null without packs. */
    public function randPerCredit(string $tenantId): ?float;

    /** @return array{date: string, total_cents: int}|null the next subscription payment, including VAT */
    public function nextPayment(string $tenantId): ?array;
}
