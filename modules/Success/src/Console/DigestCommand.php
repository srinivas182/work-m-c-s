<?php

declare(strict_types=1);

namespace Wurk\Success\Console;

use Illuminate\Console\Command;
use Wurk\Success\Support\HealthDigest;

/** Weekly: the business-health digest for WURK's success team (Sprint 16.5). */
final class DigestCommand extends Command
{
    protected $signature = 'success:weekly-digest';

    protected $description = 'Email WURK’s success team the businesses at risk';

    public function handle(HealthDigest $digest): int
    {
        $this->info($digest->send() ? 'Digest sent.' : 'No digest email is set.');

        return self::SUCCESS;
    }
}
