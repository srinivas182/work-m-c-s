<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

use Illuminate\Validation\ValidationException;

/**
 * Topping up a business's WURK credits from outside Billing (Sprint 23.4: an agent's first top-up on their phone). Any
 * amount from R20, VAT included, by WURK's online methods (Capitec Pay, SnapScan, card, mobile money) or in cash.
 */
interface CreditTopUps
{
    public const MIN_RAND = 20;

    /**
     * WURK's online methods for people: provider key => name.
     *
     * @return array<string, string>
     */
    public function methods(): array;

    /**
     * Start an online top-up; where to send the payer, or null when it can't start. The billing email is saved for the
     * business (receipts go there).
     *
     * @throws ValidationException a plain reason (amount, or credits not on sale)
     */
    public function checkout(string $tenantId, int $rand, string $billingEmail, ?string $billingPhone, string $returnUrl, ?string $method): ?string;

    /** Whether the top-up with this reference has been paid ('succeeded'), failed ('failed') or is still waiting ('pending'). */
    public function status(string $tenantId, string $reference): string;

    /**
     * Cash handed to someone working for WURK (an agent): recorded now, counted once a WURK approver confirms it.
     *
     * @throws ValidationException a plain reason
     */
    public function recordCash(string $tenantId, int $rand, string $payerName, string $receiptNumber, string $recordedBy): void;
}
