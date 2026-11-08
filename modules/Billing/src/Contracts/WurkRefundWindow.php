<?php

declare(strict_types=1);

namespace Wurk\Billing\Contracts;

/** WURK's own refund window (Sprint 14.4, decision B/C): no refunds on a subscription payment after it; agent commission is payable when it closes. */
interface WurkRefundWindow
{
    /** Days after a payment that refunds are accepted (0 = no refunds). */
    public function refundDays(): int;
}
