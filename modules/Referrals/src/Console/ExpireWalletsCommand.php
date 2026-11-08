<?php

declare(strict_types=1);

namespace Wurk\Referrals\Console;

use Illuminate\Console\Command;
use Wurk\Referrals\Support\Wallets;
use Wurk\Tenancy\Contracts\TenantContext;
use Wurk\Tenancy\Contracts\TenantDirectory;

/** Daily: unused wallet rewards older than each business's expiry expire (oldest first; logged). */
final class ExpireWalletsCommand extends Command
{
    protected $signature = 'referrals:expire-wallets';

    protected $description = 'Expire unused referral rewards past each business’s expiry';

    public function handle(TenantDirectory $directory, TenantContext $context): int
    {
        $total = 0;
        foreach ($directory->activeIds() as $id) {
            $tenant = $directory->find($id);
            if ($tenant !== null) {
                $total += (int) $context->run($tenant, fn () => app(Wallets::class)->expire());
            }
        }
        $this->info('Expired: R'.number_format($total / 100, 2).'.');

        return self::SUCCESS;
    }
}
