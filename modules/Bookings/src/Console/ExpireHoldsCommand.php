<?php

declare(strict_types=1);

namespace Wurk\Bookings\Console;

use Illuminate\Console\Command;
use Wurk\Bookings\Support\Deposits;

final class ExpireHoldsCommand extends Command
{
    protected $signature = 'bookings:expire-holds';

    protected $description = 'Release times held for deposits that weren’t paid in time';

    public function handle(Deposits $deposits): int
    {
        $this->info($deposits->expireHolds().' hold(s) released.');

        return self::SUCCESS;
    }
}
