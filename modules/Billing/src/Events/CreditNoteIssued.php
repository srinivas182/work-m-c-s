<?php

declare(strict_types=1);

namespace Wurk\Billing\Events;

/** A credit note against a paid invoice; `creditedCents` is the invoice's total credited so far (incl. VAT), of `invoiceTotalCents`. */
final readonly class CreditNoteIssued
{
    public function __construct(public string $paymentId, public int $creditedCents, public int $invoiceTotalCents) {}
}
