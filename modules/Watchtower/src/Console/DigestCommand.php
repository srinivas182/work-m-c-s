<?php

declare(strict_types=1);

namespace Wurk\Watchtower\Console;

use Illuminate\Console\Command;
use Wurk\Watchtower\Support\Alerts;

/** Daily at 08:00 SAST (Sprint 18.0): the Watchtower report — new problems, what's open, what's failing. */
final class DigestCommand extends Command
{
    protected $signature = 'watchtower:digest';

    protected $description = 'Email HQ the daily Watchtower report';

    public function handle(Alerts $alerts): int
    {
        $this->info($alerts->digest() ? 'Report sent.' : 'No Watchtower email is set.');

        return self::SUCCESS;
    }
}
