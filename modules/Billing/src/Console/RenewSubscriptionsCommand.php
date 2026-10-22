<?php

declare(strict_types=1);

namespace Wurk\Billing\Console;

use Illuminate\Console\Command;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\Mandates;
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

    public function handle(Catalog $catalog, Payments $payments, Mandates $mandates): int
    {
        $renewed = $failed = 0;

        foreach ($catalog->dueForRenewal(now()->addHour()) as $tenantId) {
            // Downgrades and yearly → monthly scheduled earlier take effect now, before charging.
            if ($catalog->applyScheduledChange($tenantId)) {
                $this->components->twoColumnDetail($tenantId, 'scheduled package change applied');
            }
            $sub = $catalog->subscriptionOf($tenantId);
            if ($sub === null) {
                continue;
            }

            $alreadyCharged = Payment::query()->where('tenant_id', $tenantId)->where('purpose', 'subscription')
                ->whereIn('status', ['pending', 'succeeded'])->where('details->renewal_of', $sub['current_period_end'])->exists();
            if ($alreadyCharged) {
                continue;
            }

            $details = ['package' => $sub['package_key'], 'period' => $sub['period'], 'renewal_of' => $sub['current_period_end']];
            $mandate = $mandates->active($tenantId);
            // Debit orders settle days later: a pending collection is "submitted", not failed.
            $payment = $mandate !== null
                ? $mandates->collect($mandate, $tenantId, $sub['period_cents'], $details)
                : $payments->chargeSaved($tenantId, 'subscription', $sub['period_cents'], $details);

            if (in_array($payment->status, ['succeeded', 'pending'], true)) {
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
