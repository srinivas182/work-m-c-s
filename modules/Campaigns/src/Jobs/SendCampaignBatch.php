<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Platform\Queues;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/**
 * One step of a campaign (Sprint 18.3a): the first step lists its customers; every later one sends the next batch,
 * then queues the step after. A campaign therefore has at most one job waiting at any time, so businesses' campaigns
 * take turns on the bulk queue instead of one 50,000-message campaign holding everyone else up.
 */
final class SendCampaignBatch implements ShouldQueue
{
    use Dispatchable, Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(public string $tenantId, public string $campaignId, public bool $first = false)
    {
        $this->onQueue(Queues::BULK);
    }

    public function handle(TenantDirectory $tenants, TenantContext $context, CampaignSender $sender): void
    {
        $tenant = $tenants->find($this->tenantId);
        if ($tenant === null) {
            return;
        }
        $more = $context->run($tenant, fn (): bool => $this->first ? $sender->prepare($this->campaignId) : $sender->sendBatch($this->campaignId));
        if ($more) {
            self::dispatch($this->tenantId, $this->campaignId);
        }
    }
}
