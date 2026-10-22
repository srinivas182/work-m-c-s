<?php

declare(strict_types=1);

namespace Wurk\Billing\Support;

use Illuminate\Support\Carbon;
use Wurk\Plans\Contracts\Catalog;

/** Whether a business must pay before using its back office (unpaid, trial ended, or dunning day 8+). */
final readonly class BillingStatus
{
    public function __construct(private Catalog $catalog, private Dunning $dunning) {}

    public function needsPayment(string $tenantId): bool
    {
        $sub = $this->catalog->subscriptionOf($tenantId);
        if ($sub === null) {
            return false;
        }

        if (in_array($this->dunning->stage($tenantId), ['restricted', 'suspended'], true)) {
            return true;
        }

        return match ($sub['status']) {
            'pending_payment' => true,
            'trial' => $sub['trial_ends_at'] !== null && Carbon::parse($sub['trial_ends_at'])->isPast(),
            default => false,
        };
    }
}
