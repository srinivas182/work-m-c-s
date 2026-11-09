<?php

declare(strict_types=1);

namespace Wurk\Campaigns\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Campaigns\Support\CampaignSender;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Every 5 minutes: send scheduled campaigns that are due (Sprint 15.3a). */
final class SendDueCommand extends Command
{
    protected $signature = 'campaigns:send-due';

    protected $description = 'Send scheduled campaigns that are due';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $n = 0;
        foreach ($tenants->activeIds() as $id) {
            $tenant = $tenants->find($id);
            if ($tenant === null || $tenant->isDemo()) {
                continue;
            }
            $n += (int) $context->run($tenant, function () use ($context): int {
                $due = DB::connection($context->connectionName())->table('campaigns')->where('tenant_id', $context->id())->where('status', 'scheduled')
                    ->where('scheduled_at', '<=', now())->pluck('id');
                foreach ($due as $campaign) {
                    try {
                        app(CampaignSender::class)->send((string) $campaign);
                    } catch (\DomainException $e) {
                        DB::connection($context->connectionName())->table('campaigns')->where('tenant_id', $context->id())->where('id', $campaign)->update(['status' => 'draft', 'updated_at' => now()]); // e.g. not enough credits: back to the owner
                    }
                }

                return $due->count();
            });
        }
        $this->info("Sent {$n} campaigns.");

        return self::SUCCESS;
    }
}
