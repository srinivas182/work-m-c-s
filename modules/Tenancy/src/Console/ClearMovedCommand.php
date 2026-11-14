<?php

declare(strict_types=1);

namespace Wurk\Tenancy\Console;

use Illuminate\Console\Command;
use Wurk\Tenancy\Actions\TenantMoves;

/** Daily (Sprint 18.4a): old copies left behind by moves and restores, 7 days on — removed. */
final class ClearMovedCommand extends Command
{
    protected $signature = 'tenants:clear-moved';

    protected $description = 'Remove the old copies of businesses that were moved or restored more than 7 days ago';

    public function handle(TenantMoves $moves): int
    {
        $this->info('Removed '.$moves->clearOld().' old copies.');

        return self::SUCCESS;
    }
}
