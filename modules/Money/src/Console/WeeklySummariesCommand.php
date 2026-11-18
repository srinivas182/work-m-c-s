<?php

declare(strict_types=1);

namespace Wurk\Money\Console;

use Illuminate\Console\Command;
use Throwable;
use Wurk\Money\Support\WeeklySummary;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Mondays (08:20 SAST): each business's "how did I do" to its owners on WhatsApp (23.5b). */
final class WeeklySummariesCommand extends Command
{
    protected $signature = 'money:weekly-summaries';

    protected $description = 'Send owners last week’s summary on WhatsApp';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $sent = 0;
        foreach (array_chunk($tenants->activeIds(), 200) as $ids) {
            foreach ($tenants->activeAmong($ids) as $tenant) {
                try {
                    $sent += (int) $context->run($tenant, fn () => app(WeeklySummary::class)->send());
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }
        $this->info("{$sent} summary(ies) sent.");

        return self::SUCCESS;
    }
}
