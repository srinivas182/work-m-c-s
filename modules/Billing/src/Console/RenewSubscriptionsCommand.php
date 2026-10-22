<?php

declare(strict_types=1);

namespace Wurk\Billing\Console;

use Illuminate\Console\Command;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\Payments;
use Wurk\Plans\Contracts\Catalog;

/**
 * Hourly: charges the saved card of every subscription whose paid period ends
 * within the hour. Never charges a period twice: success moves the period end
 * forward, failure marks the subscription past due (dunning, Sprint 3.4), an
 * existing payment for the same period is skipped, and the schedule runs on one
 * server without overlapping.
 */
final class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'billing:renew';

    protected $description = 'Charge saved cards for subscriptions that are due';

    public function handle(Catalog $catalog, Payments $payments): int
    {
        $renewed = $failed = 0;

        foreach ($catalog->dueForRenewal(now()->addHour()) as $tenantId) {
            $sub = $catalog->subscriptionOf($tenantId);
            if ($sub === null) {
                continue;
            }

            $alreadyCharged = Payment::query()->where('tenant_id', $tenantId)->where('purpose', 'subscription')
                ->whereIn('status', ['pending', 'succeeded'])->where('details->renewal_of', $sub['current_period_end'])->exists();
            if ($alreadyCharged) {
                continue;
            }

            $payment = $payments->chargeSaved($tenantId, 'subscription', $sub['period_cents'], ['package' => $sub['package_key'], 'period' => $sub['period'], 'renewal_of' => $sub['current_period_end']]);

            if ($payment->status === 'succeeded') {
                $renewed++;
            } else {
                $catalog->markPastDue($tenantId);
                $failed++;
            }
        }

        $this->components->info("Renewed {$renewed}, failed {$failed}.");

        return self::SUCCESS;
    }
}
