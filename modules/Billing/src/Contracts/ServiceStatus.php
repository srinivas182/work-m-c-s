<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

/**
 * For the website renderer (Sprint 5): a business suspended for non-payment
 * shows its public visitors a neutral "temporarily unavailable" page (PRD §11.1).
 */
interface ServiceStatus
{
    public function suspendedForNonPayment(string $tenantId): bool;
}
