<?php

declare(strict_types=1);

namespace Wurk\Crm\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Crm\Contracts\ContactsBackfillRequested;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Turn customers from before contacts existed into contacts (safe to run again). */
final class LinkExistingCommand extends Command
{
    protected $signature = 'crm:link-existing';

    protected $description = 'Make contacts of every business’s existing customers (bookings, class places, orders)';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $done = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $context->run($tenant, function () use ($context, &$done): void {
                event(new ContactsBackfillRequested($context->id()));
                $done += (int) DB::connection($context->connectionName())->table('contacts')->count();
            });
        }
        $this->info("Contacts now held across businesses: {$done}.");

        return self::SUCCESS;
    }
}
