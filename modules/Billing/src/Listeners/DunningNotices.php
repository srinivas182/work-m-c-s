<?php

declare(strict_types=1);

namespace Wurk\Billing\Listeners;

use Wurk\Billing\Events\PaymentFailed;
use Wurk\Billing\Support\Dunning;
use Wurk\Plans\Contracts\Catalog;

/** A failed subscription payment starts dunning (day 0) and tells the owner once. */
final readonly class DunningNotices
{
    public function __construct(private Catalog $catalog, private Dunning $dunning) {}

    public function handle(PaymentFailed $event): void
    {
        $before = $this->catalog->subscriptionOf($event->tenantId)['status'] ?? null;
        $this->catalog->markPastDue($event->tenantId);

        if ($before === 'active') {
            $this->dunning->notify($event->tenantId, 'failed');
        }
    }
}
