<?php

declare(strict_types=1);

namespace Wurk\Reviews\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;
use Wurk\Reviews\Support\Reviews;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Hourly (25.3): asks the customers whose visit or order finished 2 to 26 hours ago how it was. */
final class AskForReviewsCommand extends Command
{
    protected $signature = 'reviews:ask';

    protected $description = 'Ask recent customers how it was';

    public function handle(TenantDirectory $tenants, TenantContext $context): int
    {
        $asked = 0;
        $ids = DB::table('tenants')->pluck('id')->map(fn ($id) => (string) $id)->all();
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($tenants->activeAmong($chunk) as $tenant) {
                try {
                    $asked += (int) $context->run($tenant, fn () => app(Reviews::class)->askDue());
                } catch (Throwable $e) {
                    report($e); // one business failing never stops the rest
                }
            }
        }
        $this->info("{$asked} customer(s) asked.");

        return self::SUCCESS;
    }
}
