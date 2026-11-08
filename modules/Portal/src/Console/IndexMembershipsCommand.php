<?php

declare(strict_types=1);

namespace Wurk\Portal\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Wurk\Portal\Support\Memberships;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Once, after deploying 13.8: add existing portal accounts to My WURK's index. Safe to run again. */
final class IndexMembershipsCommand extends Command
{
    protected $signature = 'portal:index-memberships';

    protected $description = 'Add existing customer portal accounts to My WURK';

    public function handle(TenantDirectory $directory, TenantContext $context, Memberships $memberships): int
    {
        $n = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant === null) {
                continue;
            }
            $n += (int) $context->run($tenant, function () use ($context, $memberships): int {
                $ids = DB::connection($context->connectionName())->table('portal_customers')->where('tenant_id', $context->id())->pluck('identity_id');
                foreach ($ids as $identity) {
                    $memberships->add((string) $identity, $context->id());
                }

                return $ids->count();
            });
        }
        $this->info("Indexed {$n} portal accounts.");

        return self::SUCCESS;
    }
}
