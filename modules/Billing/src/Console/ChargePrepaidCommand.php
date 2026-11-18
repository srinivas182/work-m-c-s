<?php

declare(strict_types=1);

namespace Wurk\Billing\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Billing\Support\PrepaidPlans;
use Wurk\Plans\Contracts\Catalog;

/** Pay as you go (Sprint 22.5): take today's price from each prepaid business's credits. Safe to run again. */
final class ChargePrepaidCommand extends Command
{
    protected $signature = 'billing:charge-prepaid';

    protected $description = 'Take today’s pay-as-you-go price from each prepaid business’s WURK credits';

    public function handle(Catalog $catalog, PrepaidPlans $plans): int
    {
        $counts = ['charged' => 0, 'already' => 0, 'ran_out' => 0, 'skipped' => 0];
        foreach ($catalog->prepaidSubscribers() as $tenantId) {
            try {
                $counts[$plans->run($tenantId)]++;
            } catch (Throwable $e) {
                report($e); // one business's problem never stops the others
                $counts['skipped']++;
            }
        }
        $this->info("{$counts['charged']} charged, {$counts['ran_out']} out of credits, {$counts['already']} already done, {$counts['skipped']} skipped.");

        return self::SUCCESS;
    }
}
