<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Carbon\CarbonImmutable;
use Wurk\Billing\Contracts\SubscriptionPayments;
use Wurk\Billing\Models\Payment;

final class PaidSubscriptions implements SubscriptionPayments
{
    public function paidSubscription(string $paymentId): ?array
    {
        $p = Payment::query()->find($paymentId);
        if ($p === null || $p->purpose !== 'subscription' || $p->status !== 'succeeded') {
            return null; // subscription fees only — not credits or card checks (PRD §4.6)
        }

        return ['tenant_id' => $p->tenant_id, 'excl_cents' => $p->amount_excl_cents, 'paid_at' => CarbonImmutable::parse((string) ($p->getAttribute('paid_at') ?? $p->created_at))];
    }
}
