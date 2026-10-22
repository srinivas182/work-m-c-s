<?php

declare(strict_types=1);

namespace Wurk\Billing\Console;

use Illuminate\Console\Command;
use Wurk\Billing\Models\Payment;
use Wurk\Billing\Support\BillingSettings;
use Wurk\Billing\Support\Dunning;
use Wurk\Billing\Support\Mandates;
use Wurk\Billing\Support\Payments;
use Wurk\Plans\Contracts\Catalog;

/**
 * Daily (PRD §11.1): retries on the configured days (3, 5, 7), reminders, and
 * suspension on day 15. Each retry day charges at most once per business.
 */
final class DunningCommand extends Command
{
    protected $signature = 'billing:dunning';

    protected $description = 'Retry failed subscription payments and apply dunning stages';

    public function handle(Catalog $catalog, Dunning $dunning, BillingSettings $settings, Payments $payments, Mandates $mandates): int
    {
        $s = $settings->dunning();
        $retried = $suspended = 0;

        foreach (array_keys($catalog->inDunning()) as $tenantId) {
            $day = $dunning->day($tenantId);
            $sub = $catalog->subscriptionOf($tenantId);
            if ($day === null || $sub === null) {
                continue;
            }

            if ($sub['status'] === 'past_due' && in_array($day, $s['retry_days'], true)) {
                $already = Payment::query()->where('tenant_id', $tenantId)->where('purpose', 'subscription')->where('details->dunning_day', $day)
                    ->where('details->renewal_of', $sub['current_period_end'])->exists();
                if (! $already) {
                    $details = ['package' => $sub['package_key'], 'period' => $sub['period'], 'renewal_of' => $sub['current_period_end'], 'dunning_day' => $day];
                    $mandate = $mandates->active($tenantId);
                    $payment = $mandate !== null
                        ? $mandates->collect($mandate, $tenantId, $sub['period_cents'], $details)
                        : $payments->chargeSaved($tenantId, 'subscription', $sub['period_cents'], $details);
                    $retried++;
                    if ($payment->status === 'failed') {
                        $dunning->notify($tenantId, 'reminder');
                    }
                }
            }

            if ($sub['status'] === 'past_due' && $day >= $s['suspend_on_day'] && ($catalog->subscriptionOf($tenantId)['status'] ?? '') === 'past_due') {
                $catalog->suspendForNonPayment($tenantId);
                $dunning->notify($tenantId, 'suspended');
                $suspended++;
            }
        }

        $this->components->info("Retried {$retried}, suspended {$suspended}.");

        return self::SUCCESS;
    }
}
